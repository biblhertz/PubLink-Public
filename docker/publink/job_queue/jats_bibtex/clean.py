import re

# Define your regex substitution rules
rules = [
    (r"(<year>\d{4})\d+", r"\1"),
    (r"(\d{4}):(\d+–\d+)", r"\1, pp. \2"),
    (r"(\d{4}):(\d+)", r"\1, p. \2")
]

def apply_substitutions(text, rules):
    for find, replace in rules:
        text = re.sub(find, replace, text, flags=re.MULTILINE)
    return text

# Ask for the input file name
input_file_name = input("Enter the input file name (including extension): ")

# Generate the output file name by appending '_out' before the file extension
output_file_name = input_file_name.rsplit('.', 1)
output_file_name = f"{output_file_name[0]}_out.{output_file_name[1]}"

# Read the input file
with open(input_file_name, 'r', encoding='utf-8') as file:
    content = file.read()

# Apply the substitutions
content = apply_substitutions(content, rules)

# Write the output to the new file
with open(output_file_name, 'w', encoding='utf-8') as file:
    file.write(content)

print(f"Substitutions applied successfully. Output saved to {output_file_name}.")
