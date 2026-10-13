"""Sanity checks for the pure helpers of aw_bridge_agent.py."""
import io
import itertools

src = open("aw_bridge_agent.py", encoding="utf-8").read()
head = src.split("# ==================== SERVER COMMUNICATION ====================")[0]
ns = {}
exec(compile(head, "aw_bridge_agent.py", "exec"), ns)

clean = ns["clean_app_name"]
overlap = ns["overlap_seconds"]

cases = [
    (("chrome.exe", "Cognition - Google Chrome"), "Google Chrome"),
    (("chrome.exe", "Zoho Mail - Ulaa"), "Ulaa"),
    (("chrome.exe", ""), "Google Chrome"),
    (("ulaa.exe", "Zoho Mail - Ulaa"), "Ulaa"),
    (("msedge.exe", "News - Microsoft Edge"), "Microsoft Edge"),
    (("firefox.exe", "Docs - Mozilla Firefox"), "Mozilla Firefox"),
    (("EXCEL.EXE", "Book1 - Excel"), "Microsoft Excel"),
    (("Photoshop.exe", "poster.psd"), "Adobe Photoshop"),
    (("someapp.exe", "whatever"), "Someapp"),
    (("", ""), "Desktop / System"),
]
for (app, title), expected in cases:
    got = clean(app, title)
    assert got == expected, "clean_app_name(%r, %r) = %r, expected %r" % (app, title, got, expected)

assert overlap(100, 200, [(150, 250)]) == 50
assert overlap(100, 200, [(0, 50)]) == 0
assert overlap(100, 200, [(100, 120), (180, 400)]) == 40
print("all naming and overlap checks passed")
