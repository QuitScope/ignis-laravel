# Pixel-Differenz gleichnamiger Aufnahmen: python diff.py <dirA> [<dirB>]
# Praefixe legacy-/new- (Alt gegen Neu) bzw. before-/after- (Vorher/Nachher); mit einem Ordner liegen beide darin,
# mit zweien liegt A (legacy-/before-) und B (new-/after-). Anteil Pixel mit Graudifferenz > 25; "-full" wird uebersprungen.
# Schreibt diff-*.png (neben A) und gibt je Paar den Anteil in % aus (Tablet-Isolation: 0,0 %).
import sys, glob, os
from PIL import Image, ImageChops
a_dir = sys.argv[1]; b_dir = sys.argv[2] if len(sys.argv) > 2 else a_dir
for pa, pb in (('legacy-', 'new-'), ('before-', 'after-')):
    for f in sorted(glob.glob(os.path.join(a_dir, pa + '*.png'))):
        n = os.path.join(b_dir, os.path.basename(f).replace(pa, pb, 1))
        if not os.path.exists(n) or '-full' in f: continue
        a = Image.open(f).convert('RGB'); b = Image.open(n).convert('RGB')
        w, h = min(a.width, b.width), min(a.height, b.height)
        diff = ImageChops.difference(a.crop((0, 0, w, h)), b.crop((0, 0, w, h))).convert('L').point(lambda v: 255 if v > 25 else 0)
        diff.save(os.path.join(a_dir, os.path.basename(f).replace(pa, 'diff-', 1)))
        print(f"{os.path.basename(f)[len(pa):]:50s} {sum(diff.histogram()[255:]) / (w * h) * 100:5.1f} %")
