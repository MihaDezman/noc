#!/usr/bin/env python3
"""
NOC – uveljavitev AI popravkov v RouterOS Safe Mode prek SSH (uporabnik noc-term).

  python3 noc-apply.py <id>      (zažene ga NOC v ozadju; podatke dobi od bin/term.php apply-info)

Potek: prijava -> Ctrl+X (Safe Mode) -> ukaz za ukazom (ob napaki: Ctrl+D + prekinitev = router vse razveljavi)
-> /tool fetch na NOC (router mora še doseči NOC) -> Ctrl+X (potrditev) -> nočni backup v ozadju -> /quit.
"""
import json, os, pty, re, select, signal, subprocess, sys, time

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
TERM_PHP = os.path.join(ROOT, 'bin', 'term.php')
PHP = os.environ.get('NOC_PHP', 'php')
ANSI = re.compile(r'\x1b\[[0-9;?]*[ -/]*[@-~]|\x1b[()][0-9A-Za-z]|\x1b[=>78DEHMc]|\r')
PROMPT = re.compile(r'\[[^\]\r\n]+@[^\]\r\n]+\]\s*(<SAFE>|>)\s*$')
ERRORS = re.compile(r'(failure:|bad command name|syntax error|expected end of command|no such item|invalid value|'
                    r'input does not match|ambiguous value|not enough permissions|already have|out of range|missing value|'
                    r'expected command name|unknown parameter|such client exists|couldn\'t|can not|cannot)', re.I)


def php(*args, stdin=None):
    r = subprocess.run([PHP, TERM_PHP, *args], input=(stdin or '').encode(), stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
    return r.stdout.decode('utf-8', 'replace')


class Session:
    def __init__(self, info):
        self.log = []
        self.closed = False
        cmd = ['ssh', '-tt', '-F', '/dev/null', '-p', str(int(info['port'])), '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes',
               '-o', 'ConnectTimeout=15', '-o', 'ServerAliveInterval=15', '-o', 'StrictHostKeyChecking=accept-new',
               '-o', 'UserKnownHostsFile=' + info['known_hosts'], '-o', 'LogLevel=ERROR']
        for k in info['keys']:
            cmd += ['-i', k]
        # +ct: brez barv in brez samodejnega zaznavanja terminala (čist izpis za razčlenjevanje)
        cmd.append(f"{info['user']}+ct@{info['host']}")
        self.pid, self.fd = pty.fork()
        if self.pid == 0:
            os.environ['TERM'] = 'dumb'
            try:   # širok terminal: dolge vrstice se ne prelomijo (čist izpis za razčlenjevanje)
                import fcntl, struct, termios
                fcntl.ioctl(0, termios.TIOCSWINSZ, struct.pack('HHHH', 50, 400, 0, 0))
            except Exception:
                pass
            try:
                os.execvp('ssh', cmd)
            finally:
                os._exit(127)
        self.buf = ''

    def read_until_prompt(self, timeout):
        """Bere do poziva routerja; vrne (izpis, safe) ali (izpis, None) ob časovni omejitvi / prekinitvi"""
        end = time.time() + timeout; out = ''
        while time.time() < end:
            r, _, _ = select.select([self.fd], [], [], 0.3)
            if not r:
                continue
            try:
                data = os.read(self.fd, 65536)
            except OSError:
                data = b''
            if not data:
                self.log.append(out); return out, None
            out += data.decode('utf-8', 'replace')
            clean = ANSI.sub('', out)
            last = clean.rstrip(' ').split('\n')[-1]
            m = PROMPT.search(last)
            if m:
                self.log.append(clean)
                return clean, m.group(1) == '<SAFE>'
        self.log.append(ANSI.sub('', out))
        return ANSI.sub('', out), None

    def send(self, s):
        os.write(self.fd, s.encode('utf-8'))

    def close(self, abort=False):
        if self.closed:            # drugi klic (finally) ne sme poslati signala že pobranemu PID-u
            return
        self.closed = True
        try:
            if abort:
                self.send('\x04')       # Ctrl+D v Safe Mode: razveljavi in odjavi
                time.sleep(0.5)
            os.kill(self.pid, signal.SIGHUP)
        except OSError:
            pass
        for _ in range(30):
            try:
                if os.waitpid(self.pid, os.WNOHANG)[0]:
                    break
            except ChildProcessError:
                break
            time.sleep(0.1)
        else:
            try:
                os.kill(self.pid, signal.SIGKILL); os.waitpid(self.pid, 0)
            except (OSError, ChildProcessError):
                pass


def main(aid):
    try:
        info = json.loads(php('apply-info', str(aid)))
    except Exception:
        info = {'error': 'NOC: podatkov ni mogoče prebrati'}
    if 'error' in info:
        return
    s = Session(info)
    status, note = 'failed', ''
    try:
        out, safe = s.read_until_prompt(30)
        if safe is None:
            note = 'prijava na router ni uspela (SSH, ključ ali požarni zid)'; return
        s.send('\x18')                       # Ctrl+X: Safe Mode
        out, safe = s.read_until_prompt(15)
        if not safe:
            note = 'router ni vklopil Safe Mode'; s.close(abort=True); status = 'failed'; return
        for line in info['lines']:
            s.send(line + '\r')
            out, safe = s.read_until_prompt(60)
            if safe is None:
                note = 'router se ni odzval na: ' + line[:120]; s.close(abort=True); status = 'reverted'; return
            body = out.split('\n', 1)[1] if '\n' in out else ''
            if ERRORS.search(body):
                err = next((l.strip() for l in body.split('\n') if ERRORS.search(l)), 'napaka')
                note = f'napaka pri "{line[:100]}": {err[:150]}'; s.close(abort=True); status = 'reverted'; return
            if not safe:
                note = 'Safe Mode se je med izvajanjem sprostil (preveč sprememb?) – ustavljeno'; s.close(abort=True); status = 'reverted'; return
        # router mora še doseči NOC (internet in pot do strežnika delujeta)
        s.send('/tool fetch url="' + info['check'] + '" output=none\r')
        out, safe = s.read_until_prompt(40)
        if safe is None or not safe or json.loads(php('apply-seen', str(aid)) or 'false') is not True:
            note = 'router po spremembah ni dosegel NOC – vse razveljavljeno'; s.close(abort=True); status = 'reverted'; return
        s.send('\x18')                       # Ctrl+X: potrdi spremembe
        out, safe = s.read_until_prompt(15)
        if safe is None or safe:
            note = 'potrditev Safe Mode ni uspela – router bo spremembe razveljavil'; s.close(abort=True); status = 'reverted'; return
        s.send(':execute script="/system script run noc-backup"\r')   # nov backup za pregled
        s.read_until_prompt(15)
        s.send('/quit\r'); time.sleep(0.5)
        s.close()
        status, note = 'done', f"{len(info['lines'])} ukazov uveljavljenih"
    finally:
        if status != 'done':
            try:
                s.close(abort=True)
            except Exception:
                pass
        php('apply-done', str(aid), status, note, stdin=''.join(s.log)[-200000:])


if __name__ == '__main__':
    if len(sys.argv) != 2 or not sys.argv[1].isdigit():
        print('uporaba: noc-apply.py <id>', file=sys.stderr); sys.exit(2)
    main(int(sys.argv[1]))
