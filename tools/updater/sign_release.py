#!/usr/bin/env python3
"""Offline RELEASE OPERATOR ONLY: sign release-manifest.json with a private Ed25519 key.
Never generate or store the private key inside a repository, release ZIP or site root.
"""
import argparse
import os
import stat
from pathlib import Path
from zipfile import ZipFile
from cryptography.hazmat.primitives.serialization import load_pem_private_key
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey

parser=argparse.ArgumentParser()
parser.add_argument('--release',required=True,type=Path)
parser.add_argument('--private-key',required=True,type=Path)
parser.add_argument('--signature',required=True,type=Path)
args=parser.parse_args()
if args.private_key.is_symlink() or not args.private_key.is_file():raise SystemExit('Refused: private key path is invalid')
if os.name=='posix' and args.private_key.stat().st_mode & (stat.S_IRWXG|stat.S_IRWXO):
    raise SystemExit('Refused: private key must not be readable by group or others (chmod 600)')
if args.signature.resolve()==args.private_key.resolve():raise SystemExit('Refused: overwriting private key')
key=load_pem_private_key(args.private_key.read_bytes(),password=None)
if not isinstance(key,Ed25519PrivateKey):raise SystemExit('Ed25519 key required')
with ZipFile(args.release) as z:body=z.read('release-manifest.json')
signature=key.sign(body)
fd=os.open(args.signature,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
with os.fdopen(fd,'wb') as f:f.write(signature)
print('Detached Ed25519 signature created. Verify with an independently pinned public key before update.')
