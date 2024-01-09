import openslide
import sys

if len(sys.argv) < 2:
    raise Exception("Raw file offset extractor requires arg: the file path!")

# args: path to raw file
file = openslide.OpenSlide(sys.argv[1])
offset_x = int(file.properties['openslide.bounds-x'])
offset_y = int(file.properties['openslide.bounds-y'])
print("[", offset_x, ", ", offset_y, "]", sep="", end="")
