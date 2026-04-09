<?php

namespace Utopia\Proxy\Sockmap;

use FFI;

/**
 * Kernel zero-copy TCP relay via BPF sockmap.
 *
 * Loads a precompiled BPF program (relay.bpf.o, compiled from relay.bpf.c) at
 * worker start, then exposes an insertPair(clientFd, backendFd) API that
 * hands both fds off to the kernel. After insertion, every incoming TCP
 * segment on either socket is intercepted by an sk_skb/stream_verdict
 * program and redirected to the peer via bpf_sk_redirect_hash(), without
 * the bytes ever crossing into userspace. The proxy worker only handles
 * the initial handshake and the close event.
 *
 * Requirements:
 * - Linux 4.17+ (sockhash + sk_skb/stream_verdict)
 * - libbpf >= 1.0 as a shared library (/usr/lib/x86_64-linux-gnu/libbpf.so.1)
 * - CAP_BPF or root, OR kernel.unprivileged_bpf_disabled = 0
 * - ext-ffi
 *
 * When unavailable on the host, isAvailable() returns false and callers
 * fall through to the existing userspace relay path.
 *
 * BPF object layout (must match relay.bpf.c):
 * - SEC("sk_skb/stream_verdict") program named "relay"
 * - BPF_MAP_TYPE_SOCKHASH named "peers" — keyed by u64 4-tuple hash,
 *   values are the paired socket fds (struct sock *)
 *
 * Key encoding (must match tuple_key() in the BPF program exactly):
 *   bits 48-63: socket's own local_port (host byte order)
 *   bits 32-47: socket's remote_port as raw sockaddr_in.sin_port 16 bits
 *               (network byte order bytes read as a little-endian u16)
 *   bits 0-31 : socket's remote_ip4 as raw sockaddr_in.sin_addr.s_addr
 *               (network byte order)
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

    private const LIBC_CDEF = <<<'CDEF'
typedef unsigned int socklen_t;
int getsockname(int sockfd, void *addr, socklen_t *addrlen);
int getpeername(int sockfd, void *addr, socklen_t *addrlen);
CDEF;

    /** BPF_SK_SKB_STREAM_VERDICT from enum bpf_attach_type in <linux/bpf.h> */
    private const BPF_SK_SKB_STREAM_VERDICT = 5;

    private ?FFI $ffi = null;

    private ?FFI $libc = null;

    /** @var mixed the opaque bpf_object pointer returned from libbpf */
    private mixed $object = null;

    private int $peersFd = -1;

    private int $progFd = -1;

    private bool $available = false;

    private string $lastError = '';

    /** @var array<int, array{0: int, 1: int}> clientFd => [selfKey, peerKey] for reclaim */
    private array $allocatedPairs = [];

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
            $this->lastError = 'ext-ffi not loaded';

            return false;
        }
        if (\PHP_OS_FAMILY !== 'Linux') {
            $this->lastError = 'not Linux';

            return false;
        }
        if (!\is_file($this->bpfObjectPath)) {
            $this->lastError = "BPF object not found: {$this->bpfObjectPath}";

            return false;
        }

        try {
            $this->ffi = FFI::cdef(self::CDEF, 'libbpf.so.1');
        } catch (\Throwable $e) {
            $this->lastError = 'FFI::cdef libbpf.so.1: '.$e->getMessage();

            return false;
        }

        try {
            $this->libc = FFI::cdef(self::LIBC_CDEF, 'libc.so.6');
        } catch (\Throwable $e) {
            $this->lastError = 'FFI::cdef libc.so.6: '.$e->getMessage();

            return false;
        }

        $ffi = $this->ffi;

        $obj = self::call($ffi, 'bpf_object__open_file', $this->bpfObjectPath, null);
        if (self::isErr($ffi, $obj)) {
            $this->lastError = 'bpf_object__open_file returned error';

            return false;
        }

        $rc = self::intCall($ffi, 'bpf_object__load', $obj);
        if ($rc < 0) {
            $this->lastError = "bpf_object__load failed rc={$rc}";
            self::call($ffi, 'bpf_object__close', $obj);

            return false;
        }

        $prog = self::call($ffi, 'bpf_object__find_program_by_name', $obj, 'relay');
        $peers = self::call($ffi, 'bpf_object__find_map_by_name', $obj, 'peers');

        if ($prog === null || $peers === null) {
            $this->lastError = 'prog or map lookup by name returned null';
            self::call($ffi, 'bpf_object__close', $obj);

            return false;
        }

        $this->progFd = self::intCall($ffi, 'bpf_program__fd', $prog);
        $this->peersFd = self::intCall($ffi, 'bpf_map__fd', $peers);

        if ($this->progFd < 0 || $this->peersFd < 0) {
            $this->lastError = "fd extraction failed prog={$this->progFd} peers={$this->peersFd}";
            self::call($ffi, 'bpf_object__close', $obj);

            return false;
        }

        $attachRc = self::intCall($ffi, 'bpf_prog_attach', $this->progFd, $this->peersFd, self::BPF_SK_SKB_STREAM_VERDICT, 0);
        if ($attachRc < 0) {
            $this->lastError = "bpf_prog_attach failed rc={$attachRc}";
            self::call($ffi, 'bpf_object__close', $obj);

            return false;
        }

        $this->object = $obj;
        $this->available = true;

        return true;
    }

    public function lastError(): string
    {
        return $this->lastError;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * Hand a (proxy_accept_fd, proxy_backend_fd) pair to the kernel for
     * zero-copy relay. After this call returns true, every incoming TCP
     * segment on either fd is redirected to the peer by the sk_skb
     * program without crossing userspace. The proxy worker should not
     * read or write either fd for data after insertion — only wait for
     * close events.
     *
     * Returns true on success, false if sockmap is unavailable, either
     * tuple key cannot be resolved, or the kernel rejected the update.
     */
    public function insertPair(int $acceptFd, int $backendFd): bool
    {
        $ffi = $this->ffi;
        if (!$this->available || $ffi === null) {
            return false;
        }

        $selfKey = $this->tupleKey($acceptFd);
        $peerKey = $this->tupleKey($backendFd);
        if ($selfKey === 0 || $peerKey === 0) {
            return false;
        }

        // peers[accept tuple] = backend fd
        if (!$this->updateU64KeyI32Value($ffi, $selfKey, $backendFd)) {
            return false;
        }
        // peers[backend tuple] = accept fd
        if (!$this->updateU64KeyI32Value($ffi, $peerKey, $acceptFd)) {
            $this->deleteU64Key($ffi, $selfKey);

            return false;
        }

        $this->allocatedPairs[$acceptFd] = [$selfKey, $peerKey];

        return true;
    }

    /**
     * Release a previously inserted pair. Removes both entries from the
     * sockhash so the kernel stops redirecting.
     */
    public function removePair(int $acceptFd, int $backendFd): void
    {
        $ffi = $this->ffi;
        if (!$this->available || $ffi === null) {
            return;
        }

        $keys = $this->allocatedPairs[$acceptFd] ?? null;
        if ($keys === null) {
            return;
        }
        unset($this->allocatedPairs[$acceptFd]);

        $this->deleteU64Key($ffi, $keys[0]);
        $this->deleteU64Key($ffi, $keys[1]);
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
    }

    /**
     * Build a 64-bit 4-tuple key for a socket fd, matching tuple_key()
     * in the BPF program bit-for-bit. Uses libc getsockname/getpeername
     * via FFI so any raw kernel fd works (no Socket wrapper needed).
     *
     * Layout (must match the BPF program):
     *   bits 48-63: local port (host byte order)
     *   bits 32-47: remote port as raw network-order bytes read as u16
     *   bits 0-31 : remote IPv4 as raw network-order bytes read as u32
     *
     * Returns 0 on any failure so callers can skip insertion cleanly.
     */
    private function tupleKey(int $fd): int
    {
        $libc = $this->libc;
        if ($libc === null) {
            return 0;
        }

        // sockaddr_in is 16 bytes:
        //   +0: sa_family (2 bytes, AF_INET = 2)
        //   +2: sin_port  (2 bytes, network byte order)
        //   +4: sin_addr  (4 bytes, network byte order)
        //   +8: zero      (8 bytes padding)
        $localBuf = $libc->new('unsigned char[16]');
        $peerBuf = $libc->new('unsigned char[16]');

        // FFI needs a `socklen_t*` for the length arg. Allocate an
        // array of 1 and cast to a plain pointer so the type matches.
        $lenBox = $libc->new('socklen_t[1]');
        $lenPtr = $libc->cast('socklen_t *', FFI::addr($lenBox));

        FFI::memcpy($lenPtr, \pack('V', 16), 4);
        if (self::intCall($libc, 'getsockname', $fd, FFI::addr($localBuf), $lenPtr) < 0) {
            return 0;
        }
        FFI::memcpy($lenPtr, \pack('V', 16), 4);
        if (self::intCall($libc, 'getpeername', $fd, FFI::addr($peerBuf), $lenPtr) < 0) {
            return 0;
        }

        $localBytes = FFI::string($localBuf, 16);
        $peerBytes = FFI::string($peerBuf, 16);

        // Read the port + address fields straight out of the packed
        // sockaddr_in structs without any byte-order conversion — we
        // want the raw network-order bytes that the BPF program sees.
        $lportNet = \unpack('n', \substr($localBytes, 2, 2));
        $rportRaw = \unpack('v', \substr($peerBytes, 2, 2));  // LE read of BE bytes
        $ripRaw = \unpack('V', \substr($peerBytes, 4, 4));   // LE read of BE bytes

        if (!\is_array($lportNet) || !\is_array($rportRaw) || !\is_array($ripRaw)) {
            return 0;
        }

        $lportRaw = $lportNet[1];
        $rportRawVal = $rportRaw[1];
        $ripRawVal = $ripRaw[1];
        if (!\is_int($lportRaw) || !\is_int($rportRawVal) || !\is_int($ripRawVal)) {
            return 0;
        }
        $lport = $lportRaw;
        $rport = $rportRawVal;
        $rip = $ripRawVal;

        if ($lport <= 0) {
            return 0;
        }

        $k = ($lport & 0xffff) << 48;
        $k |= ($rport & 0xffff) << 32;
        $k |= $rip & 0xffffffff;

        return $k;
    }

    /**
     * bpf_map_update_elem with a 64-bit key and a 32-bit fd value.
     * All scalars are packed via pack() so we never touch CData field
     * access in PHPStan's sight.
     */
    private function updateU64KeyI32Value(FFI $ffi, int $key, int $value): bool
    {
        $keyBuf = $ffi->new('unsigned char[8]');
        $valBuf = $ffi->new('unsigned char[4]');
        FFI::memcpy($keyBuf, \pack('P', $key), 8); // little-endian u64
        FFI::memcpy($valBuf, \pack('V', $value), 4);

        return self::intCall($ffi, 'bpf_map_update_elem', $this->peersFd, FFI::addr($keyBuf), FFI::addr($valBuf), 0) === 0;
    }

    private function deleteU64Key(FFI $ffi, int $key): void
    {
        $keyBuf = $ffi->new('unsigned char[8]');
        FFI::memcpy($keyBuf, \pack('P', $key), 8);
        self::intCall($ffi, 'bpf_map_delete_elem', $this->peersFd, FFI::addr($keyBuf));
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
