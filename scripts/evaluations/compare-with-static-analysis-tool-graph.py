import matplotlib.pyplot as plt
import seaborn as sns
import pandas as pd

data = {
    "CVE": [
        "CVE-2024-39646",
        "CVE-2023-32740",
        "CVE-2023-2023",
        "CVE-2023-2032",
        "CVE-2022-47605",
        "CVE-2025-39400",
        "CVE-2025-1511",
        "CVE-2023-50837",
        "aryo-activity-log-2.6.1",
        "sticky-menu-2.2",
        "404-pro-3.12.0",
        "site-mailer-1",
        "site-mailer-2",
    ],
    "Psalm": [1, 1, 1, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0],
    "phpcs": [0, 1, 1, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0],
    "ASST": [1, 1, 1, 0, 0, 0, 1, 0, 0, 1, 0, 0, 0],
    "Snyk": [1, 1, 1, 0, 1, 1, 1, 0, 1, 1, 0, 0, 0],
    "SymWP": [1] * 13,
}

df = pd.DataFrame(data)
df_melted = df.melt(id_vars="CVE", var_name="Tool", value_name="Detected")

cve_count = df.drop(columns="CVE").sum()

warnings_total = {"Psalm": 6, "phpcs": 40, "ASST": 1056, "Snyk": 109, "SymWP": 13}
matched = df.drop(columns="CVE").sum().to_dict()
unknown = {k: warnings_total[k] - matched[k] for k in warnings_total}

stacked_df = pd.DataFrame(
    {
        "Tool": list(warnings_total.keys()),
        "Matched Vulnerabilities": list(matched.values()),
        "Unknown/Other Warnings": list(unknown.values()),
    }
)

box_data = pd.DataFrame(
    {
        "Psalm": [4, 0, 0, 0, 0, 0, 2, 0],
        "phpcs": [2, 2, 3, 15, 15, 1, 0, 2],
        "ASST": [33, 31, 19, 455, 455, 19, 11, 33],
        "Snyk": [4, 0, 0, 46, 44, 6, 2, 7],
        "SymWP": [1, 1, 1, 1, 1, 1, 1, 1],
    }
)

precision = {
    "Psalm": matched["Psalm"] / warnings_total["Psalm"],
    "phpcs": matched["phpcs"] / warnings_total["phpcs"],
    "ASST": matched["ASST"] / warnings_total["ASST"],
    "Snyk": matched["Snyk"] / warnings_total["Snyk"],
    "SymWP": 1.0,
}
recall = {tool: matched[tool] / len(df) for tool in matched}
precision_recall_df = pd.DataFrame(
    {
        "Tool": list(precision.keys()),
        "Precision": list(precision.values()),
        "Recall": list(recall.values()),
    }
)

fig, axs = plt.subplots(2, 2, figsize=(16, 12))

sns.barplot(x=cve_count.index, y=cve_count.values, ax=axs[0, 0])
axs[0, 0].set_title("Found Vulnerabilities Count")
axs[0, 0].set_ylabel("Count")
axs[0, 0].set_xlabel("Tool")

stacked_df.set_index("Tool")[
    ["Matched Vulnerabilities", "Unknown/Other Warnings"]
].plot(kind="bar", stacked=False, ax=axs[0, 1], color=["green", "gray"], rot=0)
axs[0, 1].set_title("Detection vs Unknown Warnings")
axs[0, 1].set_ylabel("Count")
axs[0, 1].set_yscale("log")

sns.boxplot(data=box_data, ax=axs[1, 0])
axs[1, 0].set_title("Warnings per Plugin by Tool")
axs[1, 0].set_ylabel("Number of Warnings")
axs[1, 0].set_xlabel("Tool")
axs[1, 0].set_yscale("log")

precision_recall_df.plot(kind="bar", x="Tool", ax=axs[1, 1], rot=0)
axs[1, 1].set_title("Precision and Recall per Tool")
axs[1, 1].set_ylabel("Score")

plt.tight_layout()

plt.savefig("symwp_rq3.pdf", format="pdf", bbox_inches="tight")
plt.savefig("symwp_rq3.png", dpi=300, bbox_inches="tight")

plt.show()
