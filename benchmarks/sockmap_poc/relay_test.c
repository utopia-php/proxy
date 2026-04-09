// BPF sockmap relay — end-to-end correctness + throughput PoC.
//
// What this program does:
//
//   1. Loads the precompiled BPF object relay.bpf.o via libbpf
//   2. Locates the sk_msg program and sockhash map inside it
//   3. Creates a loopback TCP server on 127.0.0.1:<chosen>
//   4. Opens two client-side TCP sockets connected through that server,
//      simulating a "client fd" and "backend fd" as the proxy would have
//      after routing. These are the two halves of a zero-copy pair.
//   5. Inserts them into the sockhash keyed by the PEER's 4-tuple so the
//      sk_msg program's `key = hash(self_tuple)` lookup finds the peer
//   6. Attaches the sk_msg program to the sockhash via
//      BPF_PROG_ATTACH / BPF_SK_MSG_VERDICT
//   7. Correctness test: sends a magic byte sequence on the client side
//      and verifies it arrives on the backend side, proving the kernel
//      path is wired up
//   8. Throughput test: writes 1 GiB through the relay while measuring
//      wall-clock time. This isolates the kernel forwarding cost with no
//      userspace copy on the relay path at all.
//
// Build:
//   clang -O2 -g -target bpf -c relay.bpf.c -o relay.bpf.o
//   gcc   -O2 -g relay_test.c -o relay_test -lbpf
//
// Run:
//   sudo ./relay_test              # correctness + throughput test
//   sudo ./relay_test -n 10737418240  # 10 GiB run
//
// Kernel requirements:
//   Linux 4.17+ (sockhash + sk_msg). 6.8 is comfortable. Needs CAP_BPF or
//   root to load the program.

#define _GNU_SOURCE
#include <arpa/inet.h>
#include <bpf/bpf.h>
#include <bpf/libbpf.h>
#include <errno.h>
#include <fcntl.h>
#include <getopt.h>
#include <netinet/in.h>
#include <netinet/tcp.h>
#include <pthread.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/socket.h>
#include <sys/time.h>
#include <time.h>
#include <unistd.h>

static uint64_t now_ns(void)
{
    struct timespec ts;
    clock_gettime(CLOCK_MONOTONIC, &ts);
    return (uint64_t)ts.tv_sec * 1000000000ULL + ts.tv_nsec;
}

// Struct matching peer_val in the BPF program.
struct peer_val {
    uint32_t idx;
};

// Set TCP_NODELAY and make the socket non-blocking for write tests.
static void tune(int fd)
{
    int one = 1;
    setsockopt(fd, IPPROTO_TCP, TCP_NODELAY, &one, sizeof(one));
    int rcvbuf = 4 * 1024 * 1024;
    setsockopt(fd, SOL_SOCKET, SO_RCVBUF, &rcvbuf, sizeof(rcvbuf));
    setsockopt(fd, SOL_SOCKET, SO_SNDBUF, &rcvbuf, sizeof(rcvbuf));
}

// Create a connected TCP pair via a loopback listener. Both fds are blocking.
// fds[0] = client side, fds[1] = server accept side.
static int make_pair(int fds[2])
{
    int listen_fd = socket(AF_INET, SOCK_STREAM, 0);
    if (listen_fd < 0) return -1;

    int one = 1;
    setsockopt(listen_fd, SOL_SOCKET, SO_REUSEADDR, &one, sizeof(one));

    struct sockaddr_in a = {0};
    a.sin_family = AF_INET;
    a.sin_addr.s_addr = htonl(INADDR_LOOPBACK);
    a.sin_port = 0;
    if (bind(listen_fd, (struct sockaddr *)&a, sizeof(a)) < 0) goto fail;
    if (listen(listen_fd, 1) < 0) goto fail;

    socklen_t slen = sizeof(a);
    if (getsockname(listen_fd, (struct sockaddr *)&a, &slen) < 0) goto fail;

    int cli = socket(AF_INET, SOCK_STREAM, 0);
    if (cli < 0) goto fail;
    if (connect(cli, (struct sockaddr *)&a, slen) < 0) { close(cli); goto fail; }

    int srv = accept(listen_fd, NULL, NULL);
    if (srv < 0) { close(cli); goto fail; }

    tune(cli);
    tune(srv);
    close(listen_fd);
    fds[0] = cli;
    fds[1] = srv;
    return 0;

fail:
    close(listen_fd);
    return -1;
}

// Extract a 4-tuple from an established TCP socket: returns local port,
// remote port, and remote IPv4 in network byte order (as the BPF program
// sees them via sk_msg_md.remote_ip4).
struct reader_arg {
    int fd;
    long long target;
    long long got;
};

static void *reader_thread(void *p)
{
    struct reader_arg *a = (struct reader_arg *)p;
    char *rx = malloc(64 * 1024);
    if (!rx) return NULL;
    while (a->got < a->target) {
        ssize_t n = recv(a->fd, rx, 64 * 1024, 0);
        if (n <= 0) break;
        a->got += n;
    }
    free(rx);
    return NULL;
}

int main(int argc, char **argv)
{
    long long target_bytes = 1024LL * 1024LL * 1024LL; // default 1 GiB
    int opt;
    while ((opt = getopt(argc, argv, "n:")) != -1) {
        switch (opt) {
        case 'n': target_bytes = atoll(optarg); break;
        default:  fprintf(stderr, "usage: %s [-n bytes]\n", argv[0]); return 1;
        }
    }

    // ---- Load BPF object ----
    struct bpf_object *obj = bpf_object__open_file("relay.bpf.o", NULL);
    if (!obj || libbpf_get_error(obj)) {
        fprintf(stderr, "bpf_object__open_file: %s\n", strerror(errno));
        return 1;
    }
    if (bpf_object__load(obj)) {
        fprintf(stderr, "bpf_object__load: %s\n", strerror(errno));
        return 1;
    }

    struct bpf_program *prog = bpf_object__find_program_by_name(obj, "relay");
    if (!prog) { fprintf(stderr, "prog `relay` not found\n"); return 1; }

    struct bpf_map *map_peers = bpf_object__find_map_by_name(obj, "peers");
    if (!map_peers) { fprintf(stderr, "map `peers` not found\n"); return 1; }
    int peers_fd = bpf_map__fd(map_peers);

    struct bpf_map *map_peer_idx = bpf_object__find_map_by_name(obj, "peer_idx");
    if (!map_peer_idx) { fprintf(stderr, "map `peer_idx` not found\n"); return 1; }
    int peer_idx_fd = bpf_map__fd(map_peer_idx);

    int prog_fd = bpf_program__fd(prog);

    // Attach the sk_msg program to the sockmap so it runs on every
    // sendmsg against a socket stored in that map.
    if (bpf_prog_attach(prog_fd, peers_fd, BPF_SK_MSG_VERDICT, 0) < 0) {
        fprintf(stderr, "bpf_prog_attach: %s\n", strerror(errno));
        return 1;
    }

    // ---- Set up two TCP pairs (the "client side" and "backend side"
    // of a proxy connection).  pair_a[0] is our "client fd"; pair_b[0]
    // is our "backend fd". We relay between them via the kernel. ----
    int pair_a[2], pair_b[2];
    if (make_pair(pair_a) < 0) { perror("make_pair a"); return 1; }
    if (make_pair(pair_b) < 0) { perror("make_pair b"); return 1; }

    int client_fd  = pair_a[0]; // data sender (writer) in throughput test
    int client_srv = pair_a[1]; // loopback-server-side counterpart (unused)
    int backend_fd = pair_b[0]; // target of the relay
    int backend_srv = pair_b[1]; // loopback-server-side counterpart
    (void)client_srv; (void)backend_srv;

    // Insert both sockets into the sockmap at known indices.
    //   index 0 = client side
    //   index 1 = backend side
    // bpf_map_update_elem on a SOCKMAP expects the value to be a pointer
    // to an int holding the fd to store.
    uint32_t k0 = 0, k1 = 1;
    if (bpf_map_update_elem(peers_fd, &k0, &client_fd, BPF_ANY) < 0) {
        fprintf(stderr, "peers[0]=client_fd: %s\n", strerror(errno));
        return 1;
    }
    if (bpf_map_update_elem(peers_fd, &k1, &backend_fd, BPF_ANY) < 0) {
        fprintf(stderr, "peers[1]=backend_fd: %s\n", strerror(errno));
        return 1;
    }

    // Seed each socket's sk_storage with the PEER's index. sk_msg reads
    // this at dispatch time to know where to redirect.
    struct peer_val client_peer  = { .idx = 1 };
    struct peer_val backend_peer = { .idx = 0 };
    if (bpf_map_update_elem(peer_idx_fd, &client_fd, &client_peer, BPF_ANY) < 0) {
        fprintf(stderr, "peer_idx[client]: %s\n", strerror(errno));
        return 1;
    }
    if (bpf_map_update_elem(peer_idx_fd, &backend_fd, &backend_peer, BPF_ANY) < 0) {
        fprintf(stderr, "peer_idx[backend]: %s\n", strerror(errno));
        return 1;
    }

    printf("sockmap wired up:\n");
    printf("  peers[0]=client_fd=%d peer_idx=1 -> backend\n", client_fd);
    printf("  peers[1]=backend_fd=%d peer_idx=0 -> client\n", backend_fd);

    // ---- Correctness test ----
    // Write a magic sequence to client_fd, read it from backend_fd.
    // If sockmap is wired correctly, the bytes appear at backend_fd
    // without anything running in userspace on the relay path.
    const char magic[] = "sockmap-zero-copy-marker-0123456789";
    ssize_t w = send(client_fd, magic, sizeof(magic), 0);
    if (w != (ssize_t)sizeof(magic)) { perror("send magic"); return 1; }

    char buf[128] = {0};
    ssize_t r = recv(backend_fd, buf, sizeof(buf) - 1, 0);
    if (r != (ssize_t)sizeof(magic) || memcmp(buf, magic, sizeof(magic)) != 0) {
        fprintf(stderr, "correctness FAIL: wrote %zd bytes, got %zd: %.*s\n",
                w, r, (int)r, buf);
        return 1;
    }
    printf("correctness OK (%zd bytes relayed via kernel)\n", r);

    // ---- Throughput test ----
    // Reader thread: drains backend_fd as fast as possible.
    // Main thread: sends target_bytes to client_fd in chunks.
    // The relay happens entirely in the kernel between the two.
    const size_t chunk = 64 * 1024;
    char *tx = malloc(chunk);
    if (!tx) { perror("malloc"); return 1; }
    memset(tx, 'x', chunk);

    struct reader_arg ra = { .fd = backend_fd, .target = target_bytes, .got = 0 };
    pthread_t tid;
    pthread_create(&tid, NULL, reader_thread, &ra);

    uint64_t t0 = now_ns();
    long long sent = 0;
    while (sent < target_bytes) {
        size_t want = (target_bytes - sent > (long long)chunk) ? chunk : (size_t)(target_bytes - sent);
        ssize_t n = send(client_fd, tx, want, 0);
        if (n <= 0) { perror("send"); break; }
        sent += n;
    }

    pthread_join(tid, NULL);
    uint64_t t1 = now_ns();

    double elapsed = (t1 - t0) / 1e9;
    double gbps = (double)sent / elapsed / (1024.0 * 1024.0 * 1024.0);
    printf("throughput: sent=%lld got=%lld elapsed=%.3fs bandwidth=%.3f GB/s\n",
           sent, ra.got, elapsed, gbps);

    free(tx);
    bpf_object__close(obj);
    return 0;
}
