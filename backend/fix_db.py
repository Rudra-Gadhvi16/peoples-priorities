import sqlite3
c = sqlite3.connect('nirdhar.db')
c.execute("UPDATE submissions SET category='utilities', ward_id='ward-3' WHERE category='Other' OR category='other'")
c.commit()
print(c.execute("SELECT category, status, ward_id FROM submissions").fetchall())
