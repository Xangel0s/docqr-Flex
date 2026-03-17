from __future__ import annotations

import argparse
import functools
import os
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path


class SpaHandler(SimpleHTTPRequestHandler):
    """Serve static files and fall back to index.html for SPA routes."""

    def __init__(self, *args, directory: str | None = None, **kwargs):
        super().__init__(*args, directory=directory, **kwargs)

    def do_GET(self) -> None:
        requested_path = Path(self.translate_path(self.path))
        if self.path.startswith("/api/"):
            self.send_error(404)
            return

        if requested_path.exists():
            return super().do_GET()

        self.path = "/index.html"
        return super().do_GET()


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--host", default="127.0.0.1")
    parser.add_argument("--port", type=int, default=4200)
    parser.add_argument("--directory", required=True)
    args = parser.parse_args()

    directory = os.fspath(Path(args.directory).resolve())
    handler = functools.partial(SpaHandler, directory=directory)
    server = ThreadingHTTPServer((args.host, args.port), handler)
    print(f"Serving SPA from {directory} on http://{args.host}:{args.port}", flush=True)
    server.serve_forever()


if __name__ == "__main__":
    main()
