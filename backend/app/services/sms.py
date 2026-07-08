import os
from twilio.rest import Client
from dotenv import load_dotenv

load_dotenv(override=True)

TWILIO_ACCOUNT_SID = os.getenv("TWILIO_ACCOUNT_SID")
TWILIO_AUTH_TOKEN = os.getenv("TWILIO_AUTH_TOKEN")
TWILIO_PHONE_NUMBER = os.getenv("TWILIO_PHONE_NUMBER")

def get_client():
    if TWILIO_ACCOUNT_SID and TWILIO_AUTH_TOKEN:
        return Client(TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN)
    return None

def send_sms(to_number: str, message: str) -> bool:
    """
    Sends an SMS using Twilio.
    Returns True if successful, False otherwise.
    """
    try:
        client = get_client()
        if not client:
            print(f"[MOCK SMS] To: {to_number} | Message: {message}")
            return True
            
        if not TWILIO_PHONE_NUMBER:
            raise ValueError("TWILIO_PHONE_NUMBER not set in environment.")

        # Ensure phone number formatting (e.g. adding + prefix if missing and assuming +91 or +1, but Twilio usually needs E.164)
        if not to_number.startswith("+"):
            to_number = "+" + to_number.lstrip("0")

        msg = client.messages.create(
            body=message,
            from_=TWILIO_PHONE_NUMBER,
            to=to_number
        )
        print(f"[SMS SUCCESS] Sent to {to_number}, SID: {msg.sid}")
        return True
    except Exception as e:
        print(f"[SMS ERROR] Failed to send to {to_number}: {e}")
        return False
