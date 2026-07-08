# Nirdhar — prototype

A citizen-submission-to-priority-ledger demo: a Flask API that classifies
submissions into themes, aggregates them into map hotspots, and blends them
with mock demographic / infrastructure / development-plan data into a
ranked, explainable priority list. 

The application has been unified, bringing the frontend and backend together under a single directory, and upgraded with modern interactive dashboards.

## Features Added

1. **Representative/MP Profile Dashboard**: Dynamic overview tracking MPLAD budget utilization (₹5 Crore total), average citizen rating, and active vs. completed project statistics.
2. **Project Completion & Review Archive**: Marks active priority items as completed, logs actual project costs, gathers 5-star citizen satisfaction ratings, and keeps a progress review text log.
3. **5-Day SLA Breach Warnings**: Automatically raises a flashing warning ("Red Signal") on items unresolved for more than 5 days.
4. **HQ Escalations**: Permits direct escalation of SLA-breaching priorities to Headquarters (District Chief, State Commissioner, etc.).
5. **Bell Notifications Panel**: A topbar bell dropdown displaying real-time alerts.
6. **Niru AI Conversational Chatbot**: A bottom-left floating assistant that answers questions about budget utilization, top priorities, SLA warnings, and drafts escalation communications.
7. **Direct Phone & Messaging Connections**: Integrated hyperlinks (`tel:...`) to call citizens directly, WhatsApp prefilled sharing links for HQ alerts, and profile configuration to link the MP's phone number.

## Run it

**One command:**

```
./start.sh
```

This installs the one dependency (Flask), starts the server, and opens
**http://localhost:5000** in your browser automatically. The frontend and
backend are joined — Flask serves the dashboard page *and* the API from
the same origin, so there's no separate server to run or port to configure.

**Manually, if you'd rather:**

```
pip install -r requirements.txt
python app.py
```

## What's real vs. mocked

- **Real:** the Flask API, SQLite database storage (`completed_projects` and `escalations` tables), keyword classifier, hotspot aggregation query, and the demand-scoring formula.
- **Mocked, clearly labelled in the code:** language detection (keyword script sniff), context database gaps, voice speech-to-text transcription mock, AI photo description mock, and the simulated SMS notification alert hook.

## API

| Method | Path | Purpose |
|---|---|---|
| `POST` | `/api/submissions` | Submit a suggestion — classifies language + category, stores it |
| `GET` | `/api/submissions` | List submissions, filterable by `ward_id` / `category` |
| `GET` | `/api/stats` | Dashboard counters, budget spent, average rating, and completed count |
| `GET` | `/api/hotspots` | Aggregated active demand by ward, for the map (excludes completed projects) |
| `GET` | `/api/ledger` | Ranked, scored priority list, including pending age in days and escalation status |
| `GET` | `/api/compare?a=category:ward_id&b=category:ward_id` | Head-to-head comparison |
| `GET` | `/api/wards` | Ward reference data |
| `POST` | `/api/projects/complete` | Save completion details (cost, rating, review comments) |
| `GET` | `/api/projects/completed` | Retrieve archived completed projects |
| `POST` | `/api/projects/escalate` | Record project escalation status to database |
| `GET` | `/api/projects/escalations` | List all escalated projects |

## Folder structure

```
nirdhar/
  app.py                   Flask API, database setup, and scoring engine
  requirements.txt         Dependencies list
  index.html               Dashboard + profile + chatbot + notification bell UI
  problem_education.png    Generated problem illustration
  problem_infrastructure.png Generated problem illustration
  problem_skilling.png     Generated problem illustration
  problem_utilities.png    Generated problem illustration
  start.sh                 Startup bash script
  README.md                Documentation
```

## Next steps for a real deployment

- Swap the keyword classifier for a multilingual NLU model (e.g. an embedding-based topic clustering pipeline) so themes aren't limited to a fixed keyword list.
- Add real speech-to-text for voice notes and a vision model for photo triage (flood water, road damage, etc.).
- Replace `CONTEXT_DATA` with a live feed from census/enrollment databases and the actual development plan register.
- Add authentication for the MP's office view and an audit trail for which ledger items were acted on.
