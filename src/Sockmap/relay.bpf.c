// BPF sockmap relay — sk_skb/stream_verdict program that redirects
// INCOMING TCP segments from one socket to its paired peer via
// bpf_sk_redirect_hash, entirely in kernel space.
//
// Design:
//
//   sockhash `peers` stores socket pointers keyed by a 64-bit value
//   derived from the SENDING socket's own 4-tuple (local_port, raw
//   remote_port bytes, remote_ip4). Userspace inserts each socket
//   under its OWN 4-tuple with its PEER as the stored value, so the
//   stream_verdict program can look itself up and receive the peer.
//
//   When a TCP segment arrives on a socket in the map, the stream
//   verdict program computes the arriving socket's 4-tuple key, looks
//   it up in the sockhash, and calls bpf_sk_redirect_hash() to forward
//   the segment to the peer socket's egress path.
//
// Byte-order note:
//
//   __sk_buff exposes `local_port` in host byte order and
//   `remote_port` + `remote_ip4` in network byte order (per
//   include/uapi/linux/bpf.h). The userspace loader must build the
//   same 64-bit key using sockaddr_in.sin_port as the raw big-endian
//   u16 (no ntohs) and sin_addr.s_addr as-is, while using ntohs() on
//   the local port to get host byte order — the tuple_key() function
//   here and in the C loader are the canonical implementation.

#include <linux/bpf.h>
#include <bpf/bpf_helpers.h>

struct {
    __uint(type, BPF_MAP_TYPE_SOCKHASH);
    __uint(max_entries, 65536);
    __type(key, __u64);
    __type(value, __u32);
} peers SEC(".maps");

static __always_inline __u64 tuple_key(struct __sk_buff *skb)
{
    // local_port: host byte order, low 16 bits of the __u32.
    // remote_port: kernel stores sk_dport<<16 in a __u32, so the port
    //              bytes live in the HIGH 16 bits and need >> 16.
    // remote_ip4:  network byte order, full 32 bits.
    __u64 k = (__u64)(skb->local_port & 0xffff);
    k = (k << 16) | (__u64)((skb->remote_port >> 16) & 0xffff);
    k = (k << 32) | (__u64)skb->remote_ip4;
    return k;
}

SEC("sk_skb/stream_verdict")
int relay(struct __sk_buff *skb)
{
    __u64 key = tuple_key(skb);
    return bpf_sk_redirect_hash(skb, &peers, &key, 0);
}

char _license[] SEC("license") = "GPL";
