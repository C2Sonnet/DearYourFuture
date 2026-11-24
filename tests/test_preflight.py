import hashlib,json,pathlib,sys,tempfile,unittest,zipfile,stat
ROOT=pathlib.Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
import preflight
class PreflightTests(unittest.TestCase):
 def setUp(self):
  self.tmp=tempfile.TemporaryDirectory(); self.addCleanup(self.tmp.cleanup); self.z=pathlib.Path(self.tmp.name)/'package.zip'
  self.data={n:(ROOT/n).read_bytes() for n in preflight.PUBLIC}
  self.data.update({'VERSION':b'0.11.0\n','deploy/nginx-rewrite.conf':preflight.REWRITE,'storage/.gitkeep':b''})
 def build(self,data=None,alter=None,extra=None,variant='Source_Repository'):
  data=dict(self.data if data is None else data)
  m={'version':'0.11.0','variant':variant,'files':{n:hashlib.sha256(b).hexdigest() for n,b in data.items()}}
  if alter:alter(m)
  with zipfile.ZipFile(self.z,'w') as z:
   for n,b in data.items():z.writestr(n,b)
   z.writestr('release-manifest.json',json.dumps(m))
   if extra:extra(z)
  return preflight.hashfile(self.z)
 def reject(self,*args,**kw):
  h=self.build(*args,**kw)
  with self.assertRaises((ValueError,KeyError,TypeError,RuntimeError,zipfile.BadZipFile)):preflight.inspect_zip(self.z,h)
 def test_valid_and_external_digest(self):
  h=self.build();self.assertEqual(preflight.inspect_zip(self.z,h)['package'],'PASS')
  with self.assertRaises(ValueError):preflight.inspect_zip(self.z,'0'*64)
 def test_c_version(self):
  for version in ('c1.0.0','c1.0.0-rc.1'):
   data={**self.data,'VERSION':(version+'\n').encode()};h=self.build(data,alter=lambda m:m.update(version=version));self.assertEqual(preflight.inspect_zip(self.z,h)['version'],version)
  self.reject({**self.data,'VERSION':b'vc1.0.0\n'},alter=lambda m:m.update(version='vc1.0.0'))
 def test_manifest_and_variant(self):
  self.reject(alter=lambda m:m['files'].pop('VERSION'))
  self.reject(alter=lambda m:m['files'].update(VERSION='0'*64))
  self.reject(alter=lambda m:m.update(version='9.9.9'))
  self.reject(variant='Private_Deployment')
 def test_unsafe_paths_and_symlink(self):
  for n in ('../outside','public/extra.php','storage/runtime.json','seed/private.zip','.env','foo/.git/config','foo/evil\nname'):
   with self.subTest(n=n):self.reject({**self.data,n:b'x'})
  def symlink(z):
   i=zipfile.ZipInfo('link');i.create_system=3;i.external_attr=(stat.S_IFLNK|0o777)<<16;z.writestr(i,'/etc/passwd')
  self.reject(extra=symlink)
 def test_viewer_update_excludes_storage(self):
  d={k:v for k,v in self.data.items() if not k.startswith("storage/")}
  h=self.build(d,variant="Viewer_Update");self.assertEqual(preflight.inspect_zip(self.z,h)["package"],"PASS")
  self.reject(self.data,variant="Viewer_Update")
 def test_rewrite_and_seed(self):
  self.reject({**self.data,'deploy/nginx-rewrite.conf':b'server {}'})
  self.reject({**self.data,preflight.SEED:b'wrong'},variant='Initial_Deployment')
if __name__=='__main__':unittest.main()
