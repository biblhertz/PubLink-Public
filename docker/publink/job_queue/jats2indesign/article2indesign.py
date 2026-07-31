import sys
from lxml import etree
import xmlformatter

# Initialize the formatter with compress=False if compression removes necessary characters
formatter = xmlformatter.Formatter(compress=True)  # Adjust settings as needed

def apply_xslt_and_minify(xml_path, xslt_path):
    # Read and minify the XML content
    with open(xml_path, 'r') as xml_file:
        xml_content = xml_file.read()
    minified_xml_content = formatter.format_string(xml_content)
    
    # Parse the minified XML content
    parser = etree.XMLParser(remove_blank_text=True)
    xml = etree.fromstring(minified_xml_content, parser=parser)
    
    # Load the XSLT
    with open(xslt_path, 'rb') as f:
        xslt = etree.XSLT(etree.parse(f))
    
    # Apply the XSLT transformation
    transformed_xml = xslt(xml)
    
    # Convert the transformed XML to string
    final_xml = etree.tostring(transformed_xml, pretty_print=False, xml_declaration=True, encoding='UTF-8', method="xml")
    return final_xml

if __name__ == "__main__":
    if len(sys.argv) != 3:
        print("Usage: python article2indesign.py <path_to_xml_file> <path_to_xslt_file>")
        sys.exit(1)
    
    xml_path = sys.argv[1]
    xslt_path = sys.argv[2]  # Now taken from command line arguments
    
    # Generate the output file name by appending "_indesign" before the file extension
    output_file_name = xml_path.rsplit('.', 1)[0] + '_indesign.' + xml_path.rsplit('.', 1)[1]
    
    # Apply XSLT and minify
    minified_xml = apply_xslt_and_minify(xml_path, xslt_path)
    
    # Save the output
    with open(output_file_name, 'wb') as f:
        f.write(minified_xml)
    
    print(f"Transformed file saved as {output_file_name}")