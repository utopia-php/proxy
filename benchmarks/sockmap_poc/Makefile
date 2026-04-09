# BPF sockmap relay PoC build.
#
# Requires: clang (BPF codegen), gcc, libbpf-dev, linux-libc-dev.
#
#   make            # build both artefacts
#   sudo ./relay_test       # run correctness + 1 GiB throughput test
#   sudo ./relay_test -n 10737418240  # 10 GiB run

CC       := gcc
CLANG    := clang
CFLAGS   := -O2 -g -Wall -Wextra
LDLIBS   := -lbpf -lpthread

BPF_TARGET  := relay.bpf.o
USER_TARGET := relay_test

all: $(BPF_TARGET) $(USER_TARGET)

$(BPF_TARGET): relay.bpf.c
	$(CLANG) -O2 -g -target bpf -D__TARGET_ARCH_x86 \
		-I/usr/include/$(shell gcc -print-multiarch) \
		-c $< -o $@

$(USER_TARGET): relay_test.c
	$(CC) $(CFLAGS) $< -o $@ $(LDLIBS)

clean:
	rm -f $(BPF_TARGET) $(USER_TARGET)

.PHONY: all clean
