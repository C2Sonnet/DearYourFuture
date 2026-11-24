import unittest, threading, subprocess, sys
from pathlib import Path
TOOLS=Path(__file__).resolve().parents[1]/"tools"
sys.path.insert(0,str(TOOLS))
from http.server import BaseHTTPRequestHandler, HTTPServer
from accept_http import origin,run
class Handler(BaseHTTPRequestHandler):
    followed=0
    def do_GET(self):
        if self.path=='/elsewhere': Handler.followed+=1
        self.send_response(302);self.send_header('Location','/elsewhere');self.end_headers()
    do_HEAD=do_POST=do_PUT=do_GET
    def log_message(self,*args): pass
class TestAcceptance(unittest.TestCase):
    def test_origin(self):
        self.assertEqual(origin('https://example.test/'),'https://example.test/')
        for v in ['http://example.test','https://user:pass@example.test','https://example.test/path','https://example.test/?q=x','https://example.test/#x']:
            with self.subTest(v=v), self.assertRaises(ValueError): origin(v)
    def test_confirmation(self):
        p=subprocess.run([sys.executable,str(TOOLS/'accept_http.py'),'https://example.test','--zip-sha256','0'*64,'--tar-sha256','0'*64],capture_output=True)
        self.assertEqual(p.returncode,2)
    def test_redirect(self):
        with HTTPServer(('127.0.0.1',0),Handler) as srv:
            t=threading.Thread(target=srv.serve_forever,daemon=True);t.start()
            try:
                result=run(f'http://127.0.0.1:{srv.server_port}','0'*64,'0'*64)
                self.assertEqual(result['passed'],0);self.assertEqual(result['total'],43);self.assertEqual(Handler.followed,0)
            finally:srv.shutdown();t.join()
if __name__=='__main__':unittest.main()
