import openslide
import sys

if len(sys.argv) < 3:
    raise Exception("Raw file meta extractor requires two args: file path, and output label image path!")

# args: path to raw file, output png path
file = openslide.OpenSlide(sys.argv[1])
label = file.associated_images["label"]

# mirax stores label upside down
if sys.argv[1].endswith(".mrxs"):
    label = label.rotate(180)
label.save(sys.argv[2])

