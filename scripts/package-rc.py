"""Build an installable staging archive from plugin runtime files only."""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib
import re
root = Path(__file__).resolve().parents[1]
source = root / 'balika'
header = (source / 'balika.php').read_text()
version = re.search(r'\* Version: ([\w.\-]+)', header).group(1)
archive = root / f'balika-{version}.zip'
files = sorted(p for p in source.rglob('*') if p.is_file())
assert files and all(p.suffix not in ('.zip', '.env') for p in files)
with ZipFile(archive, 'w', ZIP_DEFLATED) as z:
    for path in files:
        name = path.relative_to(root).as_posix()
        info = ZipInfo(name, (2026, 10, 28, 0, 0, 0))
        info.compress_type = ZIP_DEFLATED
        info.external_attr = 0o100644 << 16
        z.writestr(info, path.read_bytes())
with ZipFile(archive) as z:
    assert z.testzip() is None
    assert 'balika/balika.php' in z.namelist()
    assert all(n.startswith('balika/') and '..' not in n.split('/') for n in z.namelist())
    for path in files:
        assert z.read(path.relative_to(root).as_posix()) == path.read_bytes()
sha = hashlib.sha256(archive.read_bytes()).hexdigest()
(root / 'docs' / f'balika-{version}.sha256').write_text(f'{sha}  {archive.name}\n')
print(f'{archive.name}: {len(files)} files, {archive.stat().st_size} bytes\nSHA256: {sha}')
