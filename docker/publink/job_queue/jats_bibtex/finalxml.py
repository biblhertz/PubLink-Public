from lxml import etree
import bibtexparser
import sys

def is_valid_url(url):
    """
    Checks if a string is a valid URL. This is a simple check and can be expanded
    based on specific requirements.
    """
    return url.startswith('http://') or url.startswith('https://')

def parse_bibtex(file_path):
    """
    Parses a BibTeX file and extracts editors' information, page numbers, and URLs.
    """
    try:
        with open(file_path, encoding='utf-8') as bibtex_file:
            bib_database = bibtexparser.load(bibtex_file)
    except Exception as e:
        print(f"Error loading BibTeX file: {e}")
        return {}, {}, {}

    editors_info = {}
    pages_info = {}
    urls_info = {}  # New dictionary to store URLs
    for entry in bib_database.entries:
        key = entry.get('ID', '').replace('.', '')  # Safely get 'ID' and remove dots
        
        # Editors
        editors = entry.get('editor')
        if editors:
            editors_list = editors.split(' and ')
            editors_names = []
            for editor in editors_list:
                parts = editor.split(', ')
                if len(parts) == 2:
                    last, first = parts
                    editors_names.append({'surname': last, 'given-names': first})
            if editors_names:
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

def add_editors_and_pages_to_xml(xml_file_path, editors_info, pages_info, urls_info):
    """
    Adds editors' information, page numbers, and URLs to XML file based on matching IDs,
    placing the URL as an xlink:href attribute in the uri tag only when pub-id is not present.
    """
    try:
        tree = etree.parse(xml_file_path)
        root = tree.getroot()
    except Exception as e:
        print(f"Error parsing XML file: {e}")
        return

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
                    uri.text = urls_info[citation_id] # Set url as uri text needed for parsing
                    element_citation.append(uri)

                citations_updated += 1

    if citations_updated > 0:
        print(f"Updated {citations_updated} citations with editors' information, page numbers, and URLs.")
        filename=xml_file_path.replace('.xml', '_updated.xml')
        tree.write(filename, pretty_print=True, xml_declaration=True, encoding='UTF-8')
        print(f"Written updated JATS File to {filename}")
    else:
        print("No citations were updated. Check if citation IDs match between the XML and BibTeX files.")

def main():
    #bibtex_file_path = input("Enter the path to your BibTeX file: ")
    #xml_file_path = input("Enter the path to your JATS XML file: ")

    #get arguments from command line
    bibtex_file_path = sys.argv[1]
    xml_file_path = sys.argv[2]  

    print(f"Got {bibtex_file_path} and {xml_file_path} as inputs")

    editors_info, pages_info, urls_info = parse_bibtex(bibtex_file_path)
    if editors_info or pages_info or urls_info:
        add_editors_and_pages_to_xml(xml_file_path, editors_info, pages_info, urls_info)
    else:
        print(f"No editors or page info could be added")

if __name__ == "__main__":
    main()