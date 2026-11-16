"""Install Shadowsocks transport roundtrip regression into pinned native source."""
import argparse, shutil
from pathlib import Path
def apply(source):
    shutil.copyfile(Path(__file__).parent/'ss_ws_tls_test.go', source/'internal/kernel/xray/onenew_ss_ws_tls_test.go')
if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--source',type=Path,required=True)
    apply(p.parse_args().source)
