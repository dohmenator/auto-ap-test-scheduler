"""
seating_chart_to_college_board.py
----------------------------------
Converts a Viera High School seating chart CSV file
into College Board student roster format.

Usage:
    python seating_chart_to_college_board.py

The script will:
1. Ask you for the path to the seating chart CSV file
2. Ask you for the AP course name (must match exactly what's in the database)
3. Ask you for the exam date (e.g. May 3, 8:00 AM)
4. Write the output file to your Desktop in the
   "Uploads for AP Test Auto Scheduler" folder
"""

import csv
import os
import sys
from datetime import datetime

# ------------------------------------------------
# College Board CSV column headers (25 columns)
# ------------------------------------------------
CB_HEADERS = [
    "Student First Name",
    "Student Last Name",
    "School Code",
    "Grade",
    "Email Address",
    "AP ID",
    "Student ID",
    "Course Enrolled In",
    "Class Section Name",
    "Class Section Type",
    "Teacher Name(s)",
    "Fee Status",
    "Order Exam?",
    "Testing Window",
    "Late Order Fee",
    "Unused/Canceled Exam Fee",
    "Late-Testing Fee",
    "Exam Date",
    "Order Status",
    "SSD Materials",
    "SSD Digital Formats",
    "SSD ID",
    "Enrolled Date",
    "Latest Order Submission Date",
    "Approved SSD Accommodations",
]

# ------------------------------------------------
# Metadata rows College Board includes at the top
# ------------------------------------------------
def build_metadata_rows(course_name, exam_date):
    today = datetime.now().strftime("%B %d, %Y %I:%M %p")
    return [
        ["Student roster for Viera High School"] + [""] * 24,
        [f"Generated on {today}"] + [""] * 24,
        [""] * 25,
        [f"Filtered by: Course: {course_name}"] + [""] * 24,
        [""] * 25,
    ]

# ------------------------------------------------
# Parse the seating chart CSV
# ------------------------------------------------
def parse_seating_chart(filepath):
    students = []
    current_teacher = None
    current_section = None

    with open(filepath, newline="", encoding="utf-8-sig") as f:
        reader = csv.reader(f)
        rows = list(reader)

    for row in rows:
        if not any(row):
            continue

        # Detect section headers like "Environmental Science - Rist"
        # These rows have content in col 0 and are not numeric seat numbers
        first_col = row[0].strip() if row else ""

        # Check if this is a section header (teacher name row)
        # Pattern: "Course - TeacherName" with no numeric first column
        if (
            first_col
            and not first_col.isdigit()
            and first_col.lower() not in ["seating #", "seating#", ""]
            and "-" in first_col
            and len(row) >= 2
            and (not row[1].strip() or row[1].strip().lower() in ["", "first name"])
        ):
            # Extract teacher name from "Course - TeacherName"
            parts = first_col.split("-", 1)
            if len(parts) == 2:
                current_teacher = parts[1].strip()
                current_section = first_col.strip()
            continue

        # Skip header rows
        if first_col.lower() in ["seating #", "seating#", "#"]:
            continue

        # Skip late tester rows
        if first_col.lower() == "late tester":
            continue

        # Skip empty/placeholder rows
        if len(row) < 3:
            continue

        # Try to parse as a student row (first col is seat number)
        try:
            seat_num = int(first_col)
        except ValueError:
            continue

        first_name = row[1].strip() if len(row) > 1 else ""
        last_name = row[2].strip() if len(row) > 2 else ""
        location = row[3].strip() if len(row) > 3 else ""
        accommodations = row[4].strip() if len(row) > 4 else ""

        # Skip placeholder rows like "EMPTY"
        if first_name.upper() == "EMPTY" or last_name.upper() == "EMPTY":
            continue

        if first_name and last_name:
            students.append({
                "first_name": first_name,
                "last_name": last_name,
                "teacher": current_teacher or "",
                "section": current_section or "",
                "location": location,
                "accommodations": accommodations,
                "seat": seat_num,
            })

    return students

# ------------------------------------------------
# Build College Board format rows from students
# ------------------------------------------------
def build_cb_rows(students, course_name, exam_date, school_code="102070"):
    rows = []
    today = datetime.now().strftime("%m/%d/%Y")

    for student in students:
        row = {
            "Student First Name": student["first_name"],
            "Student Last Name": student["last_name"],
            "School Code": school_code,
            "Grade": "",
            "Email Address": "",
            "AP ID": "",
            "Student ID": "",
            "Course Enrolled In": course_name,
            "Class Section Name": student["section"],
            "Class Section Type": "Standard Full Year",
            "Teacher Name(s)": student["teacher"],
            "Fee Status": "Reduced",
            "Order Exam?": "Yes",
            "Testing Window": "Std - Digital",
            "Late Order Fee": "No",
            "Unused/Canceled Exam Fee": "No",
            "Late-Testing Fee": "No",
            "Exam Date": exam_date,
            "Order Status": "Submitted",
            "SSD Materials": "",
            "SSD Digital Formats": "",
            "SSD ID": "",
            "Enrolled Date": today,
            "Latest Order Submission Date": today,
            "Approved SSD Accommodations": student["accommodations"],
        }
        rows.append([row[h] for h in CB_HEADERS])

    return rows

# ------------------------------------------------
# Main
# ------------------------------------------------
def main():
    print("\n=== Seating Chart → College Board CSV Converter ===\n")

    # Get input file path
    input_path = input(
        "Enter the full path to the seating chart CSV file\n"
        "(e.g. C:\\Users\\Jeff\\Downloads\\env_science_seating.csv): "
    ).strip().strip('"')

    if not os.path.exists(input_path):
        print(f"\nError: File not found: {input_path}")
        sys.exit(1)

    # Get course name
    print("\nEnter the AP course name exactly as it appears in the database.")
    print("Examples: AP Biology, AP Environmental Science, AP Human Geography")
    course_name = input("Course name: ").strip()

    if not course_name:
        print("Error: Course name cannot be empty.")
        sys.exit(1)

    # Get exam date
    print("\nEnter the exam date and time.")
    print("Example: May 3, 8:00 AM  or  May 13, 12:00 PM")
    exam_date = input("Exam date: ").strip()

    if not exam_date:
        print("Error: Exam date cannot be empty.")
        sys.exit(1)

    # Parse the seating chart
    print(f"\nReading seating chart from: {input_path}")
    students = parse_seating_chart(input_path)

    if not students:
        print("Error: No students found in the file. Please check the format.")
        sys.exit(1)

    print(f"Found {len(students)} students.")

    # Build output path
    desktop = os.path.join(os.path.expanduser("~"), "Desktop")
    output_folder = os.path.join(desktop, "Uploads for AP Test Auto Scheduler")

    if not os.path.exists(output_folder):
        os.makedirs(output_folder)
        print(f"\nCreated folder: {output_folder}")

    # Build safe filename from course name
    safe_course = course_name.replace(" ", "_").replace("/", "_")
    output_filename = f"{safe_course}_roster.csv"
    output_path = os.path.join(output_folder, output_filename)

    # Write output CSV
    metadata = build_metadata_rows(course_name, exam_date)
    student_rows = build_cb_rows(students, course_name, exam_date)

    with open(output_path, "w", newline="", encoding="utf-8") as f:
        writer = csv.writer(f, quoting=csv.QUOTE_ALL)

        # Write 5 metadata rows
        for row in metadata:
            writer.writerow(row)

        # Write headers
        writer.writerow(CB_HEADERS)

        # Write student data
        for row in student_rows:
            writer.writerow(row)

    print(f"\n✅ Success! Output written to:")
    print(f"   {output_path}")
    print(f"\nSummary:")
    print(f"   Course: {course_name}")
    print(f"   Exam Date: {exam_date}")
    print(f"   Students: {len(students)}")

    # Show accommodation breakdown
    no_acc = sum(1 for s in students if not s["accommodations"])
    with_acc = len(students) - no_acc
    print(f"   No accommodations: {no_acc}")
    print(f"   With accommodations: {with_acc}")
    print(f"\nYou can now upload this file through the app's Upload Student Rosters page.")

if __name__ == "__main__":
    main()