# BPF sockmap test harness.
#
#   make                        # build test binary + BPF object
#   sudo ./relay_test           # run correctness + 1 GiB throughput test

CC     := gcc
CFLAGS := -O2 -g -Wall -Wextra
LDLIBS := -lbpf -lpthread

BPF_OBJ := ../../src/Sockmap/relay.bpf.o

all: $(BPF_OBJ) relay_test

$(BPF_OBJ):
	$(MAKE) -C ../../src/Sockmap relay.bpf.o

relay_test: relay_test.c
	$(CC) $(CFLAGS) $< -o $@ $(LDLIBS)

clean:
	rm -f relay_test

.PHONY: all clean
