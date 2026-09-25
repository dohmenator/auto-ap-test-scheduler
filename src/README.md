# Auto AP Test Scheduler

This web application helps a school AP testing coordinator manage the logistics for AP exams. It is designed to track AP tests, assign testing rooms, upload student roster data from College Board, sort students by accommodation needs, generate proctor schedules, and export CSV files for easy use in Google Sheets or other tools.

## What the app does

The app supports the full AP testing workflow:

- Manage AP tests and room assignments
  - Set the main testing room, accommodations room, and overflow room for each AP course
  - Store these settings for the current school year

- Manage teachers
  - Add AP teachers and assign them to AP courses
  - Mark teachers as active or inactive
  - Prevent inactive teachers from being automatically assigned to proctor roles

- Upload student rosters
  - Upload one College Board roster CSV at a time for each AP course
  - The app reads the roster, detects the course, and stores students in the database
  - Students are sorted into main, accommodations, overflow, or Guidance locations based on accommodations

- Generate schedules
  - Assign proctors to tests based on schedule rules
  - Generate a proctor schedule and a seating chart for each AP test

- Download reports
  - Export schedule and seating data as CSV files
  - Use the outputs in Google Sheets or other spreadsheet tools

## App structure

The project includes these main pieces:

- `index.php` — dashboard landing page
- `pages/manage-tests.php` — AP test setup and room configuration
- `pages/manage-teachers.php` — teacher management and CSV teacher upload
- `pages/upload-rosters.php` — College Board student roster upload
- `pages/generate-schedule.php` — generate proctor schedule
- `pages/generate-seating.php` — generate seating charts
- `pages/downloads.php` — view/export schedule files
- `db.php` — database connection and shared helpers
- `security.php` — security/session helpers

## CSV upload requirements

### 1) Student roster CSV from College Board

The app expects student roster files that are exported from the College Board roster system and contain the standard header names below. These headers are matched by name in the code, so the file should be ready for upload without renaming columns as long as the standard export is used.

Required header row:

Student First Name,Student Last Name,School Code,Grade,Email Address,AP ID,Student ID,Course Enrolled In,Class Section Name,Class Section Type,Teacher Name(s),Exam Date,Approved SSD Accommodations

The app reads the CSV by header names such as:

- Student First Name
- Student Last Name
- School Code
- Grade
- Email Address
- AP ID
- Student ID
- Course Enrolled In
- Class Section Name
- Class Section Type
- Teacher Name(s)
- Exam Date
- Approved SSD Accommodations

Important notes:

- The app detects the header row by looking for `Student First Name`.
- It then maps each data value by that exact header name.
- One CSV file should be uploaded for each AP course/roster.
- Rows whose `Class Section Type` contains the word `Late` are skipped.
- The app automatically clears any existing roster for the same course before inserting the new roster data for that course.

Conclusion: if the coordinator uploads the standard College Board student roster export with its normal column labels, no manual heading changes should be required.

### 2) Teacher CSV upload

The teacher upload is a separate CSV and has a simpler format.

Required header row:

Teacher Name,Test Name

Each row below the header should contain:

- Teacher Name — the full teacher name
- Test Name — the exact AP course name that exists in the app's AP Tests list

The app does not duplicate existing teacher-course records and will skip invalid or duplicate rows.

## Notes on data handling

- Students are classified by accommodation type using the `Approved SSD Accommodations` field.
- The app then assigns students to the appropriate room:
  - no accommodations or preferential seating -> main location
  - extended 100% time -> Guidance
  - 50% extended time or other accommodations -> accommodations location
- The system is designed around the AP testing workflow for a single school year.

## Recommended workflow

1. Add AP tests and assign rooms in the Manage AP Tests page.
2. Add or upload teacher assignments in the Manage Teachers page.
3. Upload one College Board roster CSV per AP exam.
4. Generate the proctor schedule.
5. Generate seating charts.
6. Download the CSV exports for distribution or spreadsheet work.

## Summary

The app is primarily an AP testing coordinator tool for scheduling, room assignment, accommodation sorting, and export reporting. For the student CSV, the direct College Board roster export is expected to be compatible without editing headings, as long as the standard College Board column names remain in place.
