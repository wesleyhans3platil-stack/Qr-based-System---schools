#!/usr/bin/env python3
"""Polling helper for dashboard data.

This script periodically fetches the dashboard JSON endpoint and prints a short summary.

Example:
  python scripts/poll_dashboard.py \
    --url http://localhost/qr\ based\ System\ -\ schools/api/dashboard_data.php \
    --cookie "PHPSESSID=..." \
    --interval 15
"""

import argparse
import json
import sys
import time
import urllib.error
import urllib.request
from typing import Optional


def fetch_dashboard(url: str, cookie: Optional[str] = None) -> dict:
    headers = {
        'User-Agent': 'qr-dashboard-poller/1.0',
        'Accept': 'application/json',
    }
    if cookie:
        headers['Cookie'] = cookie

    req = urllib.request.Request(url, headers=headers)
    with urllib.request.urlopen(req, timeout=15) as resp:
        raw = resp.read()
        return json.loads(raw.decode('utf-8'))


def summarize(data: dict) -> str:
    stats = data.get('stats', {})
    return (
        f"{time.strftime('%Y-%m-%d %H:%M:%S')} | "
        f"Schools={stats.get('total_schools')} "
        f"Present={stats.get('timed_in_today')} "
        f"Absent={stats.get('absent_today')} "
        f"Flagged={stats.get('flag_count')} "
        f"Teachers={stats.get('teachers_in')}/{stats.get('total_teachers')}"
    )


def main() -> int:
    parser = argparse.ArgumentParser(description='Poll dashboard_data.php and print summary.')
    parser.add_argument('--url', required=True, help='Full URL to api/dashboard_data.php')
    parser.add_argument('--cookie', help='Optional cookie header (e.g. "PHPSESSID=...")')
    parser.add_argument('--interval', type=int, default=15, help='Polling interval in seconds')
    args = parser.parse_args()

    url = args.url
    interval = max(1, args.interval)

    print(f"Polling {url} every {interval}s. Press Ctrl+C to stop.")
    try:
        while True:
            try:
                data = fetch_dashboard(url, args.cookie)
            except urllib.error.HTTPError as e:
                print(f"HTTP error: {e.code} {e.reason}")
            except Exception as e:
                print(f"Fetch error: {e}")
            else:
                print(summarize(data))
            time.sleep(interval)
    except KeyboardInterrupt:
        print("\nExiting.")
        return 0

    return 0


if __name__ == '__main__':
    raise SystemExit(main())
