import subprocess
import argparse
from lxml import etree

# Create a argument parser object
parser = argparse.ArgumentParser(description='Convert a BibTeX file to XML.')
parser.add_argument('-i', '--bib', type=str, required=True, help='BibTeX input file')
parser.add_argument('-o', '--output', type=str, required=True, help='Output HTML file')
parser.add_argument('-c', '--csl', type=str, required=True, help='CSL style file')
parser.add_argument('-x', '--xslt', type=str, required=True, help='XSLT transformation file')

# Parse the command-line arguments
args = parser.parse_args()

# Prepare the pandoc command
pandoc_command = [
    'pandoc',
    '-s',
    '--citeproc',
    '--bibliography', args.bib,
    '--csl', args.csl,
    '-o', args.output,
]

# Prepare the stdin for the pandoc command
pandoc_stdin = "---\ntitle: 'References:'\nnocite: '@*'\n---\n"

# Run the pandoc command and capture errors
result = subprocess.run(pandoc_command, input=pandoc_stdin, text=True, capture_output=True)
if result.returncode != 0:
    print(f"Error running pandoc command: {' '.join(pandoc_command)}")
    print(f"Return code: {result.returncode}")
    print(f"Output: {result.stdout}")
    print(f"Error: {result.stderr}")

# Read XSLT Transformation File
with open(args.xslt, 'r') as xslt_file:
    xslt_content = xslt_file.read()

# Parse XSLT
xslt_tree = etree.XML(xslt_content.encode())
transform = etree.XSLT(xslt_tree)

# Read and Transform HTML
with open(args.output, 'r') as html_file:
    html_content = html_file.read()

# Trim all whitespace
html_content = ' '.join(html_content.split())

parser = etree.XMLParser(remove_blank_text=True)
html_tree = etree.fromstring(html_content.encode(), parser)
result_tree = transform(html_tree)

# Write the result to a output XML file
xml_output_file = args.output.rsplit(".", 1)[0] + ".xml"
with open(xml_output_file, 'w') as file:
    file.write(etree.tostring(result_tree, pretty_print=False, xml_declaration=False, encoding='UTF-8').decode())