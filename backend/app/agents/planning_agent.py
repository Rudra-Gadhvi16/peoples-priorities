import os
from pydantic import BaseModel, Field
from typing import Literal, Optional

from google import genai
from google.genai import types
import json

class DemandAnalysis(BaseModel):
    status: Literal["valid", "invalid"]
    sector: Literal["Water", "Roads", "Education", "Health", "Sanitation", "Other"]
    urgency_flag: int = Field(description="1 if immediate safety hazard, 0 otherwise")
    demand_score: int = Field(ge=0, le=100, description="Integer 0-100 based on severity")
    auto_reply: str = Field(description="A context-aware text message to send back to the user")
    transcription: str = Field(description="The transcribed text from the voice note, the text message, or a detailed description of the uploaded photo.")
    ward_id: str = Field(description="The detected ward ID from the content, such as 'ward-1', 'ward-2', 'ward-4-7', 'ward-9', 'ward-11'. If none is mentioned, return 'unknown'.")

def analyze_message(message: str) -> dict:
    """
    Passes the citizen's WhatsApp message to the Gemini model to extract structured data.
    """
    return analyze_multimodal(text=message)

from tenacity import retry, stop_after_attempt, wait_exponential

@retry(stop=stop_after_attempt(3), wait=wait_exponential(multiplier=1, min=2, max=10))
def call_gemini_with_retry(client, contents, system_prompt):
    return client.models.generate_content(
        model='gemini-2.5-flash',
        contents=contents,
        config=types.GenerateContentConfig(
            system_instruction=system_prompt,
            response_mime_type="application/json",
            response_schema=DemandAnalysis,
            temperature=0.0
        ),
    )

def analyze_multimodal(text: Optional[str] = None, media_bytes: Optional[bytes] = None, mime_type: Optional[str] = None) -> dict:
    system_prompt = (
        "You are an AI backend agent for 'People's Priorities', a constituency development platform. "
        "Analyze the citizen's input (text, voice note, or photo) and extract the information according to the strict JSON schema. "
        "IMPORTANT: You must accurately recognize, understand, and transcribe all major Indian languages (including Hindi, Marathi, Gujarati, Tamil, Telugu, Bengali, Kannada, Malayalam, Punjabi, Urdu, Odia, etc.) provided in text or audio. Transcribe the audio in its original language, or translate it to English for the summary. "
        "Determine the appropriate sector, whether it's an immediate safety hazard, and a demand score based on severity. "
        "Also provide a faithful transcription of any audio, or a description of any photo, and extract the mentioned ward (e.g., 'ward-9', 'ward-4-7'). "
        "Provide a thoughtful, context-aware auto_reply to send back to them."
    )
    
    api_key = os.environ.get("GEMINI_API_KEY")
    if not api_key:
        raise ValueError("GEMINI_API_KEY environment variable is missing.")
        
    client = genai.Client(api_key=api_key)
    
    contents = []
    if text:
        contents.append(text)
    elif media_bytes:
        contents.append("Please analyze this media.")

    if media_bytes and mime_type:
        clean_mime_type = mime_type.split(";")[0]
        contents.append(
            types.Part.from_bytes(data=media_bytes, mime_type=clean_mime_type)
        )
        
    if not contents:
        contents.append("Empty submission")
        
    try:
        response = call_gemini_with_retry(client, contents, system_prompt)
        return json.loads(response.text)
    except Exception as e:
        import traceback
        tb = traceback.format_exc()
        print(f"[Gemini API Error] {e}\n{tb}")
        raise e
