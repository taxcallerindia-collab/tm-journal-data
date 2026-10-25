# tm-journal-data

Weekly data from the official **Trade Marks Journal** published by IP India
(https://search.ipindia.gov.in/IPOJournal/Journal/Trademark), used by the free
trademark search at https://www.taxcaller.com/trademark-search.

- A GitHub Actions robot (`.github/workflows/journal.yml`) runs every day, reads any new
  journal PDFs and saves one small CSV per journal in `data/journals/`.
- `data/index.json` lists every journal read, with mark counts and any problems.
- The PDFs are never stored here; only the text fields printed in the journal.

Columns: app_no, name, class, applicant, goods, filed_on, journal_no, journal_date, used_since, jurisdiction.

Source: Trade Marks Journal, Office of the Controller General of Patents, Designs and Trade Marks, Government of India.
