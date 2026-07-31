import argparse
from lxml import etree
import os

# Create the argument parser
parser = argparse.ArgumentParser()
parser.add_argument('-a', '--article', type=str, required=True, help="The article XML file")
parser.add_argument('-b', '--biblio', type=str, required=True, help="The bibliography XML file")
args = parser.parse_args()

# Parse the two XML files
article_tree = etree.parse(args.article)
biblio_tree = etree.parse(args.biblio)

# Find the <back> element in article
article_back = article_tree.find('.//back')

# If a back node doesn't exist in the article, create one
if article_back is None:
    article_back = etree.SubElement(article_tree.getroot(), 'back')

# Append the elements from back.xml to the <back> element in article
for element in biblio_tree.getroot():
    article_back.append(element)

# Construct the output filename based on the article filename
# Extract the base name without extension and add "_combined.xml"
article_base_name = os.path.splitext(os.path.basename(args.article))[0]
output_file_name = f"{article_base_name}_combined.xml"

# Save the combined XML to the new file
with open(output_file_name, 'wb') as f:
    f.write(etree.tostring(article_tree, pretty_print=False, encoding='UTF-8'))

print(f"Combined XML saved as {output_file_name}")