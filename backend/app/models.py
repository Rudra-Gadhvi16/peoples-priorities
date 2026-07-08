from sqlalchemy import Column, Integer, String, Float, ForeignKey
from .database import Base

class Submission(Base):
    __tablename__ = "submissions"
    
    id = Column(String, primary_key=True, index=True)
    text = Column(String, nullable=False)
    channel = Column(String, nullable=False)
    language = Column(String, nullable=False)
    ward_id = Column(String, nullable=True)
    category = Column(String, nullable=False)
    created_at = Column(Float, nullable=False)
    image_path = Column(String, nullable=True)
    status = Column(String, default="Pending")

class CompletedProject(Base):
    __tablename__ = "completed_projects"
    
    category = Column(String, primary_key=True)
    ward_id = Column(String, primary_key=True)
    completed_at = Column(Float, nullable=False)
    actual_cost_lakhs = Column(Float, nullable=False)
    satisfaction_rating = Column(Integer, nullable=False)
    review_text = Column(String, nullable=True)

class Escalation(Base):
    __tablename__ = "escalations"
    
    category = Column(String, primary_key=True)
    ward_id = Column(String, primary_key=True)
    escalated_at = Column(Float, nullable=False)
