import openslide
import sys

if len(sys.argv) < 2:
    raise Exception("Mirax offset extractor requires arg: mirax path!")

# args: path to mirax file, output png path
mirax = openslide.OpenSlide(sys.argv[1])
offset_x = int(mirax.properties['openslide.bounds-x'])
offset_y = int(mirax.properties['openslide.bounds-y'])
print("[", offset_x, ", ", offset_y, "]", sep="", end="")
