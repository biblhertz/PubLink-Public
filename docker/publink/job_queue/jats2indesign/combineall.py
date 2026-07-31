import argparse
import subprocess

# Set up argument parser
parser = argparse.ArgumentParser(description="Automate the process of combining JATS article and bibliography into a single XML document.")
parser.add_argument('article_path', type=str, help="The path to the JATS article XML file.")
parser.add_argument('bib_path', type=str, help="The path to the BibTeX bibliography file.")

# Parse command-line arguments
args = parser.parse_args()

# Extract base filename without extension
base_name = args.article_path.rsplit('.', 1)[0]

# Define paths and filenames based on input
xslt_path = 'jats2idml.xslt'
csl_path = 'biblhertz_csl/biblhertz.csl'
html_xslt_path = 'htmlbiblio2xml.xslt'
output_html = f'{base_name}_biblio.html'
output_biblio_xml = f'{base_name}_biblio.xml'
output_combined_xml = f'{base_name}_final.xml'

# Function to run subprocess and capture errors
def run_subprocess(command):
    result = subprocess.run(command, capture_output=True, text=True)
    if result.returncode != 0:
        print(f"Error running command: {' '.join(command)}")
        print(f"Return code: {result.returncode}")
        print(f"Output: {result.stdout}")
        print(f"Error: {result.stderr}")
    return result

# Step 1: Process JATS Article
run_subprocess(['python', 'article2indesign.py', args.article_path, xslt_path])

# Step 2: Convert Bibliography
run_subprocess(['python', 'biblio2.py', '--bib', args.bib_path, '--output', output_html, '--csl', csl_path, '--xslt', html_xslt_path])

# Step 3: Merge Article and Bibliography
article_indesign_xml = f'{base_name}_indesign.xml'
run_subprocess(['python', 'combine.py', '--article', article_indesign_xml, '--biblio', output_biblio_xml])

print(f"Process completed. Final combined XML is {output_combined_xml}")