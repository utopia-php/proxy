# Compile the BPF sockmap relay object from relay.bpf.c.
#
#   make -C src/Sockmap
#
# Requires: clang with BPF target, linux headers.

CLANG := clang

relay.bpf.o: relay.bpf.c
	$(CLANG) -O2 -g -target bpf -D__TARGET_ARCH_x86 \
		-I/usr/include/$$(gcc -print-multiarch) \
		-c $< -o $@

clean:
	rm -f relay.bpf.o

.PHONY: clean
