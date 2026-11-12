#!/usr/bin/env python3
"""Minimal SMTP sink for the disposable mail-delivery test.

It speaks just enough SMTP for PHPMailer to complete a real transaction and
writes every accepted message verbatim into --out. It never forwards mail.
"""
import argparse
import socket
import threading
from pathlib import Path

parser = argparse.ArgumentParser()
parser.add_argument("--host", default="127.0.0.1")
parser.add_argument("--port", type=int, default=2525)
parser.add_argument("--out", required=True)
args = parser.parse_args()

out_dir = Path(args.out)
out_dir.mkdir(parents=True, exist_ok=True)
counter = {"n": 0}
lock = threading.Lock()


def handle(conn: socket.socket) -> None:
    with conn:
        stream = conn.makefile("rb")
        conn.sendall(b"220 adf-sink ESMTP\r\n")
        in_data = False
        buffer = []
        while True:
            line = stream.readline()
            if not line:
                return
            if in_data:
                if line.rstrip(b"\r\n") == b".":
                    in_data = False
                    with lock:
                        counter["n"] += 1
                        index = counter["n"]
                    (out_dir / f"msg-{index}.eml").write_bytes(b"".join(buffer))
                    buffer = []
                    conn.sendall(b"250 2.0.0 Ok: queued\r\n")
                else:
                    buffer.append(line)
                continue
            command = line.strip().upper()
            if command.startswith(b"EHLO") or command.startswith(b"HELO"):
                conn.sendall(b"250-adf-sink\r\n250-PIPELINING\r\n250 SIZE 10485760\r\n")
            elif command.startswith(b"MAIL FROM") or command.startswith(b"RCPT TO"):
                conn.sendall(b"250 2.1.0 Ok\r\n")
            elif command.startswith(b"DATA"):
                conn.sendall(b"354 End data with <CR><LF>.<CR><LF>\r\n")
                in_data = True
            elif command.startswith(b"RSET") or command.startswith(b"NOOP"):
                conn.sendall(b"250 2.0.0 Ok\r\n")
            elif command.startswith(b"QUIT"):
                conn.sendall(b"221 2.0.0 Bye\r\n")
                return
            else:
                conn.sendall(b"502 5.5.2 Command not implemented\r\n")


server = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
server.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
server.bind((args.host, args.port))
server.listen(5)
print(f"LISTENING {args.host}:{args.port} -> {out_dir}", flush=True)
while True:
    client, _ = server.accept()
    threading.Thread(target=handle, args=(client,), daemon=True).start()
