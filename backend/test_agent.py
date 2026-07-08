import os
from dotenv import load_dotenv
load_dotenv()
from app.agents.planning_agent import analyze_message

try:
    result = analyze_message("The street lights in Ward 3 have been broken for weeks!")
    print(result)
except Exception as e:
    import traceback
    traceback.print_exc()
