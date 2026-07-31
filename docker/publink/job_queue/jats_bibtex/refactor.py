import re
from lxml import etree
import bibtexparser

def get_doctype(xml_content):
    doctype_pattern = r'<!DOCTYPE [^>]+>'
    match = re.search(doctype_pattern, xml_content)
    if match:
        return match.group()
    return None


# Define your regex substitution rules
rules = [
    (r"(<year>\d{4})\d+", r"\1"), #needed due to a Sciflow export error when full date y-m is available
    (r"(\d{4}):(\d+–\d+)", r"\1, pp. \2"), # used to change the references from 2024:12-15 to 2024, pp. 12-15 
    (r"(\d{4}):(\d+)", r"\1, p. \2") ## used to change the references from 2024:12 to 2024, p. 12
]

def apply_substitutions(text, rules):
    for find, replace in rules:
        text = re.sub(find, replace, text, flags=re.MULTILINE)
    return text

def apply_xslt_transformation(xml_content, xslt_path):
    xml_root = etree.fromstring(xml_content.encode('utf-8'))
    xslt_root = etree.parse(xslt_path)
    transform = etree.XSLT(xslt_root)
    transformed_root = transform(xml_root)
    return etree.tostring(transformed_root, pretty_print=True, xml_declaration=True, encoding='UTF-8').decode('utf-8')

def is_valid_url(url):
    return url.startswith('http://') or url.startswith('https://')

def parse_bibtex(file_path):
    with open(file_path, encoding='utf-8') as bibtex_file:
        bib_database = bibtexparser.load(bibtex_file)
    editors_info, pages_info, urls_info = {}, {}, {}
    for entry in bib_database.entries:
        key = entry.get('ID', '').replace('.', '')
        # Process editors, pages, and URLs as before
         # Editors
        editors = entry.get('editor')
        if editors:
            editors_list = editors.split(' and ')
            editors_names = [{'surname': last, 'given-names': first} for editor in editors_list if (last := editor.split(', ')[0]) and (first := editor.split(', ')[1])]
            editors_info[key] = editors_names
        
        # Pages
        pages = entry.get('pages')
        if pages:
            pages_split = pages.split('--')
            if len(pages_split) == 2:
                pages_info[key] = {'fpage': pages_split[0], 'lpage': pages_split[1]}
        
        # URLs
        url = entry.get('annotation') or entry.get('howpublished') or entry.get('url')
        if url and is_valid_url(url):
            urls_info[key] = url
        
        print(f"Processed BibTeX ID: {key}")
        
    return editors_info, pages_info, urls_info

def add_editors_and_pages_to_xml(xml_content, editors_info, pages_info, urls_info):
    root = etree.fromstring(xml_content.encode('utf-8'))
    """
    Adds editors' information, page numbers, and URLs to XML file based on matching IDs,
    placing the URL as an xlink:href attribute in the uri tag only when pub-id is not present.
    """
    citations_updated = 0

    # Ensure the namespace for xlink is declared
    xlink_ns = "http://www.w3.org/1999/xlink"
    etree.register_namespace('xlink', xlink_ns)

    for ref in root.findall('.//ref'):
        citation_id = ref.get('id')
        if citation_id in editors_info or citation_id in pages_info or citation_id in urls_info:
            element_citation = ref.find('.//element-citation')
            if element_citation is not None:
                # Check for 'pub-id' element to determine where to insert pages and URL
                pub_id = element_citation.find('.//pub-id')

                # Add editors
                if citation_id in editors_info:
                    source = element_citation.find('.//source')
                    if source is not None:
                        person_group = etree.Element("person-group", attrib={"person-group-type": "editor"})
                        for editor in editors_info[citation_id]:
                            name_element = etree.SubElement(person_group, "name")
                            etree.SubElement(name_element, "surname").text = editor['surname']
                            etree.SubElement(name_element, "given-names").text = editor['given-names']
                        source.addprevious(person_group)

                # Add pages
                if citation_id in pages_info:
                    fpage = etree.Element("fpage")
                    fpage.text = pages_info[citation_id]['fpage']
                    lpage = etree.Element("lpage")
                    lpage.text = pages_info[citation_id]['lpage']
                    if pub_id is not None:
                        pub_id.addprevious(fpage)
                        pub_id.addprevious(lpage)
                    else:
                        element_citation.append(fpage)
                        element_citation.append(lpage)

                # Add URL as <uri> with xlink:href only when pub-id is missing
                if citation_id in urls_info and pub_id is None:
                    uri = etree.Element("uri")
                    uri.set(f"{{{xlink_ns}}}href", urls_info[citation_id])  # Set xlink:href attribute
                    element_citation.append(uri)

                citations_updated += 1

    if citations_updated > 0:
        print(f"Updated {citations_updated} citations with editors' information, page numbers, and URLs.")
    else:
        print("No citations were updated. Check if citation IDs match between the XML and BibTeX files.")

    return etree.tostring(root, pretty_print=True, xml_declaration=False, encoding='UTF-8').decode('utf-8')

def main_workflow(input_file_name, xslt_path, bibtex_path):
    # Read the input XML file


    # Step 1: Read the input XML file and apply regex substitutions
    with open(input_file_name, 'r', encoding='utf-8') as file:
        content = file.read()
    # Capture the original DOCTYPE
    original_doctype = get_doctype(content)
    content = apply_substitutions(content, rules)

    # Step 2: Apply XSLT transformation
    content = apply_xslt_transformation(content, xslt_path)

    # Step 3: Parse BibTeX and add editors, pages, and URLs to XML
    editors_info, pages_info, urls_info = parse_bibtex(bibtex_path)
    content = add_editors_and_pages_to_xml(content, editors_info, pages_info, urls_info)

    # Save the final output
    output_file_name = input_file_name.rsplit('.', 1)[0] + '_final.xml'
    with open(output_file_name, 'w', encoding='utf-8') as file:
       file.write('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>')
       if original_doctype:
        file.write(original_doctype + '\n')
        file.write(content)
    print(f"Workflow completed successfully. Output saved to {output_file_name}.")

if __name__ == "__main__":
    input_file_name = input("Enter the input file name (including extension): ")
#    xslt_path = input("Enter the path to your XSLT file: ") #enable to choose the file path
    xslt_path = "refactor.xslt" # disable if you need to input the filename
    bibtex_path = input("Enter the path to your BibTeX file: ")
    main_workflow(input_file_name, xslt_path, bibtex_path)
