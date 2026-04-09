// BPF sockmap relay — sk_msg program that redirects sendmsg() output from
// one socket in the map to its paired peer, entirely in kernel space.
//
// Design:
//
//   peer_idx  (BPF_MAP_TYPE_SK_STORAGE): per-socket storage containing the
//             sockmap index of the PAIRED socket. Userspace sets this via
//             bpf_map_update_elem with the socket fd as key at setup time.
//
//   peers     (BPF_MAP_TYPE_SOCKMAP): stores the actual struct sock pointers
//             by integer index. Pair N lives at indices (N*2, N*2+1).
//
//   sk_msg program: reads the running socket's own peer index from
//             sk_storage, then calls bpf_msg_redirect_map() to forward the
//             message to that index. No byte-order juggling, no tuple
//             reconstruction.

#include <linux/bpf.h>
#include <bpf/bpf_helpers.h>

struct peer_val {
    __u32 idx;
};

struct {
    __uint(type, BPF_MAP_TYPE_SK_STORAGE);
    __uint(map_flags, BPF_F_NO_PREALLOC);
    __type(key, int);
    __type(value, struct peer_val);
} peer_idx SEC(".maps");

struct {
    __uint(type, BPF_MAP_TYPE_SOCKMAP);
    __uint(max_entries, 65536);
    __type(key, __u32);
    __type(value, __u32);
} peers SEC(".maps");

SEC("sk_msg")
int relay(struct sk_msg_md *msg)
{
    struct peer_val *pv = bpf_sk_storage_get(&peer_idx, msg->sk, NULL, 0);
    if (!pv) {
        return SK_PASS;
    }
    return bpf_msg_redirect_map(msg, &peers, pv->idx, BPF_F_INGRESS);
}

char _license[] SEC("license") = "GPL";
