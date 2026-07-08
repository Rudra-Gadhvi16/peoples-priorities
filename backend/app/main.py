from fastapi import FastAPI, Form, Request, Response, Depends, HTTPException, Query
from fastapi.middleware.cors import CORSMiddleware
from twilio.twiml.messaging_response import MessagingResponse
from dotenv import load_dotenv
from pydantic import BaseModel
import os
import uuid
import time
from sqlalchemy.orm import Session
from sqlalchemy import func

from app.agents.planning_agent import analyze_message
from app.database import engine, get_db, Base
from app import models

# Create all tables
Base.metadata.create_all(bind=engine)

# Load environment variables
load_dotenv(override=True)

# Initialize FastAPI application
app = FastAPI(title="People's Priorities API")

# Add CORS Middleware to allow all origins
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

from fastapi.staticfiles import StaticFiles
from fastapi.responses import FileResponse
import os

frontend_dir = os.path.abspath(os.path.join(os.path.dirname(__file__), "../../frontend/nirdhar"))

app.mount("/assets", StaticFiles(directory=os.path.join(frontend_dir, "assets")), name="assets")
app.mount("/images", StaticFiles(directory=os.path.join(frontend_dir, "images")), name="images")

@app.get("/")
def serve_index():
    return FileResponse(os.path.join(frontend_dir, "index.html"))

def extract_ward_from_text(text: str) -> str:
    # A simple mock ward extractor if not provided. In real life, Gemini can extract this.
    text_lower = text.lower()
    for w in ['ward-1', 'ward-2', 'ward-3', 'ward-4-7', 'ward-9', 'ward-11']:
        if w.replace('-', ' ') in text_lower or w in text_lower:
            return w
        # Quick fallback for "ward 3" -> "ward-3"
        if w.replace('-', '') in text_lower.replace(' ', ''):
            return w
    return "ward-1"  # Default fallback if not detected

@app.post("/api/whatsapp")
async def whatsapp_webhook(Body: str = Form(...), db: Session = Depends(get_db)):
    """
    Webhook endpoint to catch incoming WhatsApp messages from Twilio.
    """
    try:
        analysis_result = analyze_message(Body)
        
        # Determine ward based on text if not extracted
        ward_id = extract_ward_from_text(Body)
        
        # Save to DB
        sub_id = str(uuid.uuid4())
        raw_cat = analysis_result.get("sector", "Other").lower()
        if raw_cat in ["roads", "infrastructure"]:
            cat = "infrastructure"
        elif raw_cat in ["water", "sanitation", "utilities"]:
            cat = "utilities"
        elif raw_cat in ["education", "skilling"]:
            cat = "education"
        else:
            cat = "general"
        
        new_sub = models.Submission(
            id=sub_id,
            text=Body,
            channel="whatsapp",
            language="en",
            ward_id=ward_id,
            category=cat,
            created_at=time.time(),
            status="Pending"
        )
        db.add(new_sub)
        db.commit()
        
        auto_reply = analysis_result.get(
            "auto_reply", 
            "Thank you for your message. We have received your priority and are processing it."
        )
    except Exception as e:
        print(f"Error processing message: {e}")
        auto_reply = "We are currently experiencing high volume. Please try again later."
    
    twiml_response = MessagingResponse()
    twiml_response.message(auto_reply)
    return Response(content=str(twiml_response), media_type="application/xml")

# --- Frontend Endpoints ---

import base64
from app.agents.planning_agent import analyze_multimodal

class AnalyzeRequest(BaseModel):
    mode: str
    text: str | None = None
    media_base64: str | None = None
    mime_type: str | None = None

@app.post("/api/analyze")
def analyze_input(req: AnalyzeRequest):
    try:
        media_bytes = None
        if req.media_base64:
            b64_str = req.media_base64
            if "," in b64_str:
                b64_str = b64_str.split(",")[1]
            media_bytes = base64.b64decode(b64_str)
            
        result = analyze_multimodal(
            text=req.text,
            media_bytes=media_bytes,
            mime_type=req.mime_type
        )
        return {"status": "success", "analysis": result}
    except Exception as e:
        print(f"Error analyzing multimodal input: {e}")
        raise HTTPException(status_code=500, detail=str(e))

@app.get("/api/submissions/status")
def get_submission_status(id: str, db: Session = Depends(get_db)):
    sub = db.query(models.Submission).filter(models.Submission.id == id).first()
    if not sub:
        raise HTTPException(status_code=404, detail="Not found")
    
    # We should return some mock rank/score based on active ledger
    return {
        "id": sub.id, 
        "status": sub.status,
        "category": sub.category,
        "ward_name": sub.ward_id.replace('-', ' ').title() if sub.ward_id else "Unknown",
        "rank": 1 if sub.status == 'Pending' else -1,
        "total_ledger_items": 10,
        "demand_score": 85,
        "text": sub.text,
        "image_path": sub.image_path,
        "resolution": None
    }

class SubmissionRequest(BaseModel):
    text: str
    channel: str | None = "web"
    language: str | None = "en"
    ward_id: str | None = None
    image: str | None = None
    image_name: str | None = None
    category: str | None = None

@app.post("/api/submissions")
@app.post("/submission")
def create_submission(req: SubmissionRequest, db: Session = Depends(get_db)):
    category = req.category
    ward_id = req.ward_id
    
    if not category:
        try:
            analysis = analyze_multimodal(text=req.text)
            category = analysis.get("sector", "Other").lower()
            if analysis.get("ward_id") and analysis.get("ward_id") != "unknown" and not ward_id:
                ward_id = analysis.get("ward_id")
        except Exception as e:
            print(f"Failed to classify category: {e}")
            category = "Other"

    sub_id = str(uuid.uuid4())
    new_sub = models.Submission(
        id=sub_id,
        text=req.text,
        channel=req.channel,
        language=req.language,
        ward_id=ward_id,
        category=category,
        created_at=time.time(),
        status="Pending"
    )
    db.add(new_sub)
    db.commit()
    return {"id": sub_id, "status": "success", "category": category, "ward_id": ward_id}

@app.get("/api/submissions")
@app.get("/submission")
def get_submissions(has_image: bool = False, db: Session = Depends(get_db)):
    query = db.query(models.Submission)
    if has_image:
        query = query.filter(models.Submission.image_path.isnot(None))
    return query.order_by(models.Submission.created_at.desc()).all()

def _get_ledger_items(db: Session):
    # Group by category and ward
    subs = db.query(models.Submission).filter(models.Submission.status.in_(["Pending", "Escalated"])).all()
    groups = {}
    for sub in subs:
        key = (sub.category, sub.ward_id)
        if key not in groups:
            groups[key] = {
                "category": sub.category,
                "ward_id": sub.ward_id,
                "ward_name": sub.ward_id.replace('-', ' ').title() if sub.ward_id else "Unknown",
                "mentions": 0,
                "age_days": 0,
                "escalated": False,
                "context": {"beneficiaries": 500}
            }
        groups[key]["mentions"] += 1
        age = int((time.time() - sub.created_at) / 86400)
        groups[key]["age_days"] = max(groups[key]["age_days"], age)
        if sub.status == "Escalated":
            groups[key]["escalated"] = True

    # Check actual escalations
    escalated_projects = db.query(models.Escalation).all()
    for esc in escalated_projects:
        key = (esc.category, esc.ward_id)
        if key in groups:
            groups[key]["escalated"] = True

    items = list(groups.values())
    for item in items:
        # Simple scoring formula
        score = min(100, item["mentions"] * 10 + item["age_days"] * 5)
        if item["escalated"]:
            score += 20
        item["demand_score"] = min(100, score)
    
    items.sort(key=lambda x: x["demand_score"], reverse=True)
    
    for i, item in enumerate(items):
        item["rank"] = i + 1
        item["rank_label"] = f"#{i+1} Priority"
        
    return items

@app.get("/api/ledger")
@app.get("/ledger")
def get_ledger(db: Session = Depends(get_db)):
    return _get_ledger_items(db)

@app.get("/api/compare")
def compare_wards(a: str, b: str, db: Session = Depends(get_db)):
    items = _get_ledger_items(db)
    
    cat_a, ward_a = a.split(':')
    cat_b, ward_b = b.split(':')
    
    item_a = next((i for i in items if i["category"] == cat_a and i["ward_id"] == ward_a), None)
    item_b = next((i for i in items if i["category"] == cat_b and i["ward_id"] == ward_b), None)
    
    if not item_a:
        item_a = {"category": cat_a, "ward_id": ward_a, "ward_name": ward_a.title(), "demand_score": 10, "mentions": 0, "context": {}}
    if not item_b:
        item_b = {"category": cat_b, "ward_id": ward_b, "ward_name": ward_b.title(), "demand_score": 10, "mentions": 0, "context": {}}
        
    recommended = 'a' if item_a["demand_score"] >= item_b["demand_score"] else 'b'
    
    return {
        "a": item_a,
        "b": item_b,
        "recommended": recommended
    }

class CompleteProjectRequest(BaseModel):
    category: str
    ward_id: str
    actual_cost: float
    satisfaction_rating: int
    review_text: str | None = None

@app.post("/api/projects/complete")
def complete_project(req: CompleteProjectRequest, db: Session = Depends(get_db)):
    proj = models.CompletedProject(
        category=req.category,
        ward_id=req.ward_id,
        completed_at=time.time(),
        actual_cost_lakhs=req.actual_cost,
        satisfaction_rating=req.satisfaction_rating,
        review_text=req.review_text
    )
    db.merge(proj)
    # Mark related submissions as resolved
    db.query(models.Submission).filter(
        models.Submission.ward_id == req.ward_id, 
        models.Submission.category == req.category
    ).update({"status": "Resolved"})
    db.commit()
    return {"status": "success"}

@app.get("/api/projects/completed")
def get_completed_projects(db: Session = Depends(get_db)):
    return db.query(models.CompletedProject).all()

class EscalateRequest(BaseModel):
    category: str
    ward_id: str

class PhoneSettingsRequest(BaseModel):
    phone_number: str

AUTHORITY_PHONE = None

@app.post("/api/settings/phone")
def set_authority_phone(req: PhoneSettingsRequest):
    global AUTHORITY_PHONE
    AUTHORITY_PHONE = req.phone_number
    return {"status": "success"}

@app.post("/api/projects/escalate")
def escalate_project(req: EscalateRequest, db: Session = Depends(get_db)):
    esc = models.Escalation(
        category=req.category,
        ward_id=req.ward_id,
        escalated_at=time.time()
    )
    db.merge(esc)
    db.query(models.Submission).filter(
        models.Submission.ward_id == req.ward_id, 
        models.Submission.category == req.category
    ).update({"status": "Escalated"})
    db.commit()
    
    global AUTHORITY_PHONE
    if AUTHORITY_PHONE:
        msg = f"🚨 Nirdhar Alert: A {req.category.title()} grievance in {req.ward_id.replace('-', ' ').title()} has been escalated to HQ. Immediate action required."
        send_sms(AUTHORITY_PHONE, msg)
        
    return {"status": "success"}

@app.get("/api/stats")
@app.get("/report")
def get_stats(db: Session = Depends(get_db)):
    total = db.query(models.Submission).count()
    completed_projects = db.query(models.CompletedProject).all()
    
    utilized = sum([p.actual_cost_lakhs for p in completed_projects])
    total_budget = 500.0
    completed_count = len(completed_projects)
    
    if completed_count > 0:
        avg_satisfaction = round(sum([p.satisfaction_rating for p in completed_projects]) / completed_count, 1)
    else:
        avg_satisfaction = 0.0

    return {
        "total_submissions": total,
        "utilized_budget_lakhs": utilized,
        "total_budget_lakhs": total_budget,
        "remaining_budget_lakhs": total_budget - utilized,
        "completed_count": completed_count,
        "average_satisfaction": avg_satisfaction
    }

@app.get("/api/hotspots")
@app.get("/hotspots")
def get_hotspots(db: Session = Depends(get_db)):
    # Group by ward and category
    hotspots = db.query(
        models.Submission.ward_id,
        models.Submission.category,
        func.count(models.Submission.id).label("issue_count")
    ).filter(models.Submission.status.in_(["Pending", "Escalated"])).group_by(models.Submission.ward_id, models.Submission.category).all()
    
    result = []
    
    # Static coordinates for demo purposes
    ward_coords = {
        "ward-1": {"lat": 150, "lng": 80},
        "ward-2": {"lat": 250, "lng": 100},
        "ward-3": {"lat": 200, "lng": 150},
        "ward-4-7": {"lat": 280, "lng": 180},
        "ward-9": {"lat": 100, "lng": 200},
        "ward-11": {"lat": 180, "lng": 250},
    }
    
    for idx, h in enumerate(hotspots):
        severity = "High" if h.issue_count > 10 else "Medium"
        coords = ward_coords.get(h.ward_id, {"lat": 200, "lng": 160})
        
        result.append({
            "id": idx + 1,
            "ward_id": h.ward_id or "Unknown",
            "name": h.ward_id.replace('-', ' ').title() if h.ward_id else "Unknown",
            "top_category": h.category,
            "total_mentions": h.issue_count,
            "severity": severity,
            "lat": coords["lat"],
            "lng": coords["lng"]
        })
    return result
