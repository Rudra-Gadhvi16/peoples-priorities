import requests
import json
import time

BASE_URL = "http://localhost:8000"

def test():
    print("Testing POST /api/submissions")
    res = requests.post(f"{BASE_URL}/api/submissions", json={
        "text": "The road is very broken here",
        "category": "Roads",
        "ward_id": "Ward 5",
        "language": "en"
    })
    print(res.status_code, res.json())
    sub_id = res.json()["id"]

    print("Testing GET /api/submissions/status")
    res = requests.get(f"{BASE_URL}/api/submissions/status?id={sub_id}")
    print(res.status_code, res.json())

    print("Testing GET /api/submissions")
    res = requests.get(f"{BASE_URL}/api/submissions")
    print(res.status_code, len(res.json()), "submissions")

    print("Testing POST /api/projects/escalate")
    res = requests.post(f"{BASE_URL}/api/projects/escalate", json={
        "category": "Roads",
        "ward_id": "Ward 5"
    })
    print(res.status_code, res.json())

    print("Testing POST /api/projects/complete")
    res = requests.post(f"{BASE_URL}/api/projects/complete", json={
        "category": "Roads",
        "ward_id": "Ward 5",
        "actual_cost_lakhs": 25.5,
        "satisfaction_rating": 4,
        "review_text": "Good work"
    })
    print(res.status_code, res.json())

    print("Testing GET /api/stats")
    res = requests.get(f"{BASE_URL}/api/stats")
    print(res.status_code, res.json())

    print("Testing GET /api/ledger")
    res = requests.get(f"{BASE_URL}/api/ledger")
    print(res.status_code, res.json())

    print("Testing GET /api/hotspots")
    res = requests.get(f"{BASE_URL}/api/hotspots")
    print(res.status_code, res.json())

    print("Testing GET /api/compare")
    res = requests.get(f"{BASE_URL}/api/compare?a=Ward 5&b=Ward 2")
    print(res.status_code, res.json())

if __name__ == "__main__":
    test()
