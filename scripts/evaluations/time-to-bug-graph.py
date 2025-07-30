import pandas as pd
import matplotlib.pyplot as plt
import io

data_string = """
Plugin,Test Version,CVE ID,v2 1st run,v2 2nd run,v2 3rd run,v2 4th run,v2 5th run,v2 Avg.,V2 Min.,v2 Max.
#1 custom-404-pro,3.2.7,CVE-2024-39646,130.41,150.58,150.39,145.46,146.66,144.7,130.41,150.58
#2 custom-404-pro,3.2.7,CVE-2023-32740,151.66,164.73,201.8,178.82,155.68,170.53,151.66,201.8
#3 custom-404-pro,3.2.7,CVE-2023-2023,152.62,175.72,170.6,165.69,171.73,170.93,152.62,175.72
#4 custom-404-pro,3.2.7,CVE-2023-2032,167.94,164.45,221.37,230.24,153.82,187.56,153.82,230.24
#5 custom-404-pro,3.2.7,CVE-2022-47605,186.9,167.67,202.78,191.94,153.5,180.56,153.5,202.78
#6 user-registration,4.1.5,CVE-2025-39400,147.31,129.25,131.28,130.33,128.28,133.29,128.28,147.31
#7user-registration,4.0.4,CVE-2025-1511,150.59,149.82,139.5,142.88,140.62,144.68,139.5,150.59
#8 aryo-activity-log,2.6.1,No CVE,192.46,184.32,184,191.8,191.39,187.88,184,192.46
#9 sticky-menu-or-anything-on-scroll,2.2,No CVE,122.68,146.65,137.58,141.03,142.15,138.02,122.68,146.65
#10 login-lockdown,2.06,CVE-2023-50837,175.43,150.37,172.38,148.15,157.2,160.71,148.15,175.43
#11 custom-404-pro,3.12.0(latest),0day,290.96,153.74,173.72,306.6,271.05,239.21,153.74,306.6
#12 site-mailer,1.2.6(latest),0day,274.08,309.83,364.08,321.82,459.24,345.81,274.08,459.24
#13 site-mailer,1.2.6(latest),0day,,1711.12,1377.47,1287.74,1258.75,1408.77,1258.75,1711.12
"""
df = pd.read_csv(io.StringIO(data_string))
df.rename(
    columns={"v2 Avg.": "v2 Avg", "V2 Min.": "v2 Min", "v2 Max.": "v2 Max"},
    inplace=True,
)

df["x_label"] = df["Plugin"] + " (" + df["CVE ID"] + ")"
lower_error = df["v2 Avg"] - df["v2 Min"]
upper_error = df["v2 Max"] - df["v2 Avg"]
asymmetric_error = [lower_error, upper_error]

fig, ax = plt.subplots(figsize=(16, 9))

bars = ax.bar(
    df["x_label"],
    df["v2 Avg"],
    yerr=asymmetric_error,
    capsize=5,
    color="skyblue",
    edgecolor="black",
    label="Average Time",
    width=0.5,
)

ax.set_ylabel("Time-to-Bug (seconds)", fontsize=15)
ax.set_xlabel("Plugin (CVE ID)", fontsize=15)
ax.set_yscale("log")

plt.xticks(rotation=45, ha="right", fontsize=11)

ax.bar_label(bars, fmt="%.0f", padding=5, fontsize=10)

ax.legend(fontsize=12)

ax.grid(axis="y", linestyle="--", alpha=0.7)

plt.tight_layout()

plt.savefig("time_to_bug_chart.pdf", format="pdf", bbox_inches="tight")
plt.savefig("time_to_bug_chart.png", dpi=300, bbox_inches="tight")

plt.show()
