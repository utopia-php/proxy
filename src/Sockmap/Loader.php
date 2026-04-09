<?php

namespace Utopia\Proxy\Sockmap;

use FFI;

/**
 * Kernel zero-copy TCP relay via BPF sockmap.
 *
 * Loads a precompiled BPF program (benchmarks/sockmap_poc/relay.bpf.o) at
 * worker start, then exposes an insertPair(clientFd, backendFd) API that
 * hands both fds off to the kernel. After insertion, every sendmsg() on
 * either socket is routed to the peer by the sk_msg program without
 * crossing userspace — the proxy worker only handles the initial
 * handshake and the close event.
 *
 * Requirements:
 * - Linux 4.17+ (sockmap + sk_msg + sk_storage)
 * - libbpf >= 1.0 as a shared library (/usr/lib/x86_64-linux-gnu/libbpf.so.1)
 * - CAP_BPF or root, OR kernel.unprivileged_bpf_disabled = 0
 * - ext-ffi
 *
 * When unavailable on the host, isAvailable() returns false and callers
 * fall through to the existing userspace relay path.
 *
 * BPF object layout (must match relay.bpf.c):
 * - SEC("sk_msg") program named "relay"
 * - BPF_MAP_TYPE_SOCKMAP named "peers" — stores actual socket pointers by index
 * - BPF_MAP_TYPE_SK_STORAGE named "peer_idx" — per-socket uint32 peer index
 *
 * The loader allocates contiguous index pairs (2N, 2N+1) for each inserted
 * (client, backend) pair, writes both fds into `peers`, and sets each
 * socket's peer_idx entry to the peer's index. Release reverses the map
 * updates.
 */
class Loader
{
    private const CDEF = <<<'CDEF'
typedef struct bpf_object bpf_object;
typedef struct bpf_program bpf_program;
typedef struct bpf_map bpf_map;

struct bpf_object *bpf_object__open_file(const char *path, const void *opts);
int bpf_object__load(struct bpf_object *obj);
void bpf_object__close(struct bpf_object *obj);

struct bpf_program *bpf_object__find_program_by_name(const struct bpf_object *obj, const char *name);
int bpf_program__fd(const struct bpf_program *prog);

struct bpf_map *bpf_object__find_map_by_name(const struct bpf_object *obj, const char *name);
int bpf_map__fd(const struct bpf_map *map);

int bpf_prog_attach(int prog_fd, int attachable_fd, int type, unsigned int flags);

int bpf_map_update_elem(int fd, const void *key, const void *value, unsigned long long flags);
int bpf_map_delete_elem(int fd, const void *key);

long libbpf_get_error(const void *ptr);
CDEF;

    /** BPF_SK_MSG_VERDICT from <linux/bpf.h> */
    private const BPF_SK_MSG_VERDICT = 24;

    private ?FFI $ffi = null;

    /** @var mixed the opaque bpf_object pointer returned from libbpf */
    private mixed $object = null;

    private int $peersFd = -1;

    private int $peerIdxFd = -1;

    private int $progFd = -1;

    private bool $available = false;

    /** @var array<int, int> clientFd => peer-index base (so we can reclaim on remove) */
    private array $allocatedPairs = [];

    private int $nextPairBase = 0;

    public function __construct(
        private readonly string $bpfObjectPath,
    ) {
    }

    /**
     * Attempt to load the BPF object and wire up the maps + program.
     *
     * Returns true on success, false if any step fails (FFI missing,
     * libbpf missing, BPF load denied, etc.). On failure the loader is
     * inert and insertPair() is a no-op.
     */
    public function load(): bool
    {
        if (!\extension_loaded('ffi')) {
            return false;
        }
        if (\PHP_OS_FAMILY !== 'Linux') {
            return false;
        }
        if (!\is_file($this->bpfObjectPath)) {
            return false;
        }

        try {
            $this->ffi = FFI::cdef(self::CDEF, 'libbpf.so.1');
        } catch (\Throwable $e) {
            return false;
        }

        $ffi = $this->ffi;

        $obj = self::call($ffi, 'bpf_object__open_file', $this->bpfObjectPath, null);
        if (self::isErr($ffi, $obj)) {
            return false;
        }

        if (self::intCall($ffi, 'bpf_object__load', $obj) < 0) {
            self::call($ffi, 'bpf_object__close', $obj);

            return false;
        }

        $prog = self::call($ffi, 'bpf_object__find_program_by_name', $obj, 'relay');
        $peers = self::call($ffi, 'bpf_object__find_map_by_name', $obj, 'peers');
        $peerIdx = self::call($ffi, 'bpf_object__find_map_by_name', $obj, 'peer_idx');

        if ($prog === null || $peers === null || $peerIdx === null) {
            self::call($ffi, 'bpf_object__close', $obj);

            return false;
        }

        $this->progFd = self::intCall($ffi, 'bpf_program__fd', $prog);
        $this->peersFd = self::intCall($ffi, 'bpf_map__fd', $peers);
        $this->peerIdxFd = self::intCall($ffi, 'bpf_map__fd', $peerIdx);

        if ($this->progFd < 0 || $this->peersFd < 0 || $this->peerIdxFd < 0) {
            self::call($ffi, 'bpf_object__close', $obj);

            return false;
        }

        if (self::intCall($ffi, 'bpf_prog_attach', $this->progFd, $this->peersFd, self::BPF_SK_MSG_VERDICT, 0) < 0) {
            self::call($ffi, 'bpf_object__close', $obj);

            return false;
        }

        $this->object = $obj;
        $this->available = true;

        return true;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * Hand a (client, backend) fd pair to the kernel for zero-copy relay.
     *
     * After this call returns successfully, every sendmsg() on either fd
     * is redirected to the peer by the kernel without crossing userspace.
     * The proxy worker should not read or write either fd for data after
     * insertion — only wait for close events.
     *
     * Returns true on success, false if sockmap is unavailable or the
     * kernel rejected the update.
     */
    public function insertPair(int $clientFd, int $backendFd): bool
    {
        $ffi = $this->ffi;
        if (!$this->available || $ffi === null) {
            return false;
        }

        $base = $this->nextPairBase;
        $clientIdx = $base;
        $backendIdx = $base + 1;
        $this->nextPairBase += 2;

        $peersFd = $this->peersFd;
        $peerIdxFd = $this->peerIdxFd;

        // peers[clientIdx] = clientFd
        if (!$this->updateU32($ffi, $peersFd, $clientIdx, $clientFd)) {
            return false;
        }

        // peers[backendIdx] = backendFd
        if (!$this->updateU32($ffi, $peersFd, $backendIdx, $backendFd)) {
            $this->deleteU32($ffi, $peersFd, $clientIdx);

            return false;
        }

        // peer_idx[clientFd] = backendIdx (clientFd's peer is backend)
        if (!$this->updateU32($ffi, $peerIdxFd, $clientFd, $backendIdx)) {
            $this->rollback($ffi, $clientIdx, $backendIdx);

            return false;
        }

        // peer_idx[backendFd] = clientIdx (backendFd's peer is client)
        if (!$this->updateU32($ffi, $peerIdxFd, $backendFd, $clientIdx)) {
            $this->rollback($ffi, $clientIdx, $backendIdx);
            $this->deleteU32($ffi, $peerIdxFd, $clientFd);

            return false;
        }

        $this->allocatedPairs[$clientFd] = $base;

        return true;
    }

    /**
     * bpf_map_update_elem with a 4-byte little-endian key and 4-byte
     * little-endian value. Both are built from PHP ints via pack() so
     * we never touch CData property access in PHPStan's sight.
     */
    private function updateU32(FFI $ffi, int $mapFd, int $key, int $value): bool
    {
        $keyBuf = $ffi->new('unsigned char[4]');
        $valBuf = $ffi->new('unsigned char[4]');
        FFI::memcpy($keyBuf, \pack('V', $key), 4);
        FFI::memcpy($valBuf, \pack('V', $value), 4);

        return self::intCall($ffi, 'bpf_map_update_elem', $mapFd, FFI::addr($keyBuf), FFI::addr($valBuf), 0) === 0;
    }

    private function deleteU32(FFI $ffi, int $mapFd, int $key): void
    {
        $keyBuf = $ffi->new('unsigned char[4]');
        FFI::memcpy($keyBuf, \pack('V', $key), 4);
        self::intCall($ffi, 'bpf_map_delete_elem', $mapFd, FFI::addr($keyBuf));
    }

    /**
     * Release a previously inserted pair. Removes both sockets from the
     * sockmap and their sk_storage entries, freeing the index slots.
     */
    public function removePair(int $clientFd, int $backendFd): void
    {
        $ffi = $this->ffi;
        if (!$this->available || $ffi === null) {
            return;
        }

        $base = $this->allocatedPairs[$clientFd] ?? null;
        if ($base === null) {
            return;
        }
        unset($this->allocatedPairs[$clientFd]);

        $this->rollback($ffi, $base, $base + 1);
        $this->deleteU32($ffi, $this->peerIdxFd, $clientFd);
        $this->deleteU32($ffi, $this->peerIdxFd, $backendFd);
    }

    /**
     * Close the BPF object and release all kernel resources.
     */
    public function close(): void
    {
        if ($this->ffi !== null && $this->object !== null) {
            self::call($this->ffi, 'bpf_object__close', $this->object);
            $this->object = null;
        }
        $this->available = false;
        $this->allocatedPairs = [];
        $this->nextPairBase = 0;
    }

    private function rollback(FFI $ffi, int $clientIdx, int $backendIdx): void
    {
        $this->deleteU32($ffi, $this->peersFd, $clientIdx);
        $this->deleteU32($ffi, $this->peersFd, $backendIdx);
    }

    /**
     * Variable-method dispatch to keep PHPStan happy with libbpf's
     * dynamic function names (declared via CDEF, not visible to static
     * analysis). Returns mixed; callers narrow.
     */
    private static function call(FFI $ffi, string $fn, mixed ...$args): mixed
    {
        return $ffi->$fn(...$args);
    }

    /**
     * @phpstan-impure libbpf syscalls return different values across invocations
     */
    private static function intCall(FFI $ffi, string $fn, mixed ...$args): int
    {
        $result = self::call($ffi, $fn, ...$args);

        return \is_int($result) ? $result : -1;
    }

    private static function isErr(FFI $ffi, mixed $ptr): bool
    {
        if ($ptr === null) {
            return true;
        }
        $rc = self::call($ffi, 'libbpf_get_error', $ptr);

        return \is_int($rc) && $rc !== 0;
    }
}
