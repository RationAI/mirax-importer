import openslide
import sys

if len(sys.argv) < 3:
    raise Exception("Mirax meta extractor requires two args: mirax path, and output label image path!")

# args: path to mirax file, output png path and output metadata path
mirax = openslide.OpenSlide(sys.argv[1])
label = mirax.associated_images["label"].rotate(180)
label.save(sys.argv[2])




