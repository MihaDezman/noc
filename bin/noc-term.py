#!/usr/bin/env python3
"""
NOC – most za terminal routerjev: WebSocket (brskalnik, prek Apache /term/ws) <-> ssh (router).

Teče kot www-data (systemd: deploy/term/noc-term.service) in posluša samo na 127.0.0.1:8765.
Žeton preveri bin/term.php (enkraten, 60 s, vezan na uporabnika in IP), nato zažene ssh v psevdoterminalu.
Odjemalec pošilja JSON: {"t":"i","d":"<vnos>"} ali {"t":"r","c":<stolpci>,"r":<vrstice>}; strežnik pošilja surov izpis (binarno).
Potrebuje: python3-websockets (Debian paket), OpenSSH odjemalec, php-cli.
"""
import asyncio, fcntl, json, os, pty, signal, struct, sys, termios, time, urllib.parse

try:   # websockets 13+ (nova izvedba) ali starejši (10–12)
    from websockets.asyncio.server import serve as ws_serve
except ImportError:
    from websockets import serve as ws_serve

HOST, PORT = '127.0.0.1', 8765
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
TERM_PHP = os.path.join(ROOT, 'bin', 'term.php')
PHP = os.environ.get('NOC_PHP', 'php')
IDLE_MAX = 30 * 60          # brez vnosa 30 min -> konec
SESSION_MAX = 4 * 3600      # najdaljša seja
LOG_MAX = 5 * 1024 * 1024   # izpis seje največ 5 MB


def log(msg):
    print(time.strftime('%Y-%m-%d %H:%M:%S'), msg, flush=True)


async def php(*args):
    p = await asyncio.create_subprocess_exec(PHP, TERM_PHP, *args, stdout=asyncio.subprocess.PIPE, stderr=asyncio.subprocess.DEVNULL)
    out, _ = await p.communicate()
    return out.decode('utf-8', 'replace')


def req_info(ws):
    """Pot in glave zahteve (nova in stara izvedba websockets)"""
    r = getattr(ws, 'request', None)
    if r is not None and hasattr(r, 'path'):
        return r.path, r.headers
    return getattr(ws, 'path', ''), getattr(ws, 'request_headers', {})


def set_size(fd, cols, rows):
    try:
        cols = max(20, min(500, int(cols))); rows = max(5, min(200, int(rows)))
        fcntl.ioctl(fd, termios.TIOCSWINSZ, struct.pack('HHHH', rows, cols, 0, 0))
    except Exception:
        pass


async def handler(ws, *_):
    path, headers = req_info(ws)
    q = urllib.parse.parse_qs(urllib.parse.urlparse(path).query)
    tok = (q.get('t') or [''])[0]
    ip = (headers.get('X-Forwarded-For') or '').split(',')[0].strip()
    try:
        info = json.loads(await php('check', tok, ip))
    except Exception:
        info = {'error': 'NOC: preverjanje žetona ni uspelo'}
    if 'error' in info:
        await ws.send(('\r\n\x1b[31m' + info['error'] + '\x1b[0m\r\n').encode())
        await ws.close()
        return
    sid = int(info['id'])
    log(f"seja #{sid} {info['name']} ({info['host']}) z {ip or '?'}")
    cmd = ['ssh', '-tt', '-F', '/dev/null', '-p', str(int(info['port'])),
           '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-o', 'ConnectTimeout=10',
           '-o', 'ServerAliveInterval=30', '-o', 'StrictHostKeyChecking=accept-new',
           '-o', 'UserKnownHostsFile=' + info['known_hosts'], '-o', 'LogLevel=ERROR']
    for k in info['keys']:
        cmd += ['-i', k]
    cmd.append(f"{info['user']}@{info['host']}")

    pid, fd = pty.fork()
    if pid == 0:   # otrok: ssh v psevdoterminalu
        os.environ['TERM'] = 'xterm-256color'
        try:
            os.execvp('ssh', cmd)
        finally:
            os._exit(127)
    set_size(fd, (q.get('c') or ['120'])[0], (q.get('r') or ['32'])[0])
    os.set_blocking(fd, False)
    loop = asyncio.get_running_loop()
    out_q = asyncio.Queue()

    def readable():
        try:
            data = os.read(fd, 65536)
        except (BlockingIOError, InterruptedError):
            return
        except OSError:
            data = b''
        out_q.put_nowait(data)
        if not data:
            loop.remove_reader(fd)
    loop.add_reader(fd, readable)

    total = 0; started = time.time(); last_in = time.time(); reason = 'odjava'
    try:
        logf = open(info['log'], 'ab')
        os.chmod(info['log'], 0o640)
    except OSError:
        logf = None

    async def pump_out():
        nonlocal total
        while True:
            data = await out_q.get()
            if not data:
                return
            total += len(data)
            if logf and logf.tell() < LOG_MAX:
                logf.write(data)
            await ws.send(data)

    async def pump_in():
        nonlocal last_in
        async for msg in ws:
            try:
                m = json.loads(msg)
            except Exception:
                continue
            if m.get('t') == 'i':
                last_in = time.time()
                os.write(fd, str(m.get('d', '')).encode('utf-8', 'replace'))
            elif m.get('t') == 'r':
                set_size(fd, m.get('c', 120), m.get('r', 32))

    async def watchdog():
        nonlocal reason
        while True:
            await asyncio.sleep(20)
            if time.time() - last_in > IDLE_MAX:
                reason = 'neaktivnost'; await ws.send(b'\r\n\x1b[33m[NOC] seja zaprta zaradi neaktivnosti\x1b[0m\r\n'); return
            if time.time() - started > SESSION_MAX:
                reason = 'najdaljsi cas'; await ws.send(b'\r\n\x1b[33m[NOC] seja je dosegla najdaljsi cas (4 h)\x1b[0m\r\n'); return

    tasks = [asyncio.ensure_future(pump_out()), asyncio.ensure_future(pump_in()), asyncio.ensure_future(watchdog())]
    try:
        done, pending = await asyncio.wait(tasks, return_when=asyncio.FIRST_COMPLETED)
        if tasks[0] in done:
            reason = 'router zaprl povezavo'
            try:
                await ws.send(b'\r\n\x1b[90m[NOC] povezava zaprta\x1b[0m\r\n')
            except Exception:
                pass
        elif tasks[1] in done:
            reason = 'brskalnik zaprt'
        for t in pending:
            t.cancel()
    finally:
        try:
            loop.remove_reader(fd)
        except Exception:
            pass
        try:
            os.kill(pid, signal.SIGHUP)
        except ProcessLookupError:
            pass
        try:
            os.close(fd)
        except OSError:
            pass
        for _ in range(20):   # ssh se ob SIGHUP konča; po 2 s ga ustavimo na silo
            try:
                if os.waitpid(pid, os.WNOHANG)[0]:
                    break
            except ChildProcessError:
                break
            await asyncio.sleep(0.1)
        else:
            try:
                os.kill(pid, signal.SIGKILL); os.waitpid(pid, 0)
            except (ProcessLookupError, ChildProcessError):
                pass
        if logf:
            logf.close()
        try:
            await ws.close()
        except Exception:
            pass
        await php('end', str(sid), str(total), reason)
        log(f"seja #{sid} konec ({reason}, {total} B)")


async def main():
    async with ws_serve(handler, HOST, PORT, max_size=1 << 20, ping_interval=25):
        log(f"NOC terminal most posluša na {HOST}:{PORT}")
        await asyncio.Future()


if __name__ == '__main__':
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        sys.exit(0)
