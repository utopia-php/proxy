# BPF sockmap relay build.
#
# The BPF source lives in src/Sockmap/relay.bpf.c (production code).
# This Makefile builds the compiled object and the test harness.
#
#   make                        # build both artefacts
#   sudo ./relay_test           # run correctness + 1 GiB throughput test

CC       := gcc
CLANG    := clang
CFLAGS   := -O2 -g -Wall -Wextra
LDLIBS   := -lbpf -lpthread

BPF_SRC     := ../../src/Sockmap/relay.bpf.c
BPF_TARGET  := relay.bpf.o
USER_TARGET := relay_test

all: $(BPF_TARGET) $(USER_TARGET)

$(BPF_TARGET): $(BPF_SRC)
	$(CLANG) -O2 -g -target bpf -D__TARGET_ARCH_x86 \
		-I/usr/include/$$(gcc -print-multiarch) \
		-c $< -o $@

$(USER_TARGET): relay_test.c
	$(CC) $(CFLAGS) $< -o $@ $(LDLIBS)

clean:
	rm -f $(BPF_TARGET) $(USER_TARGET)

.PHONY: all clean
