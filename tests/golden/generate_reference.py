#!/usr/bin/env python3
"""Erzeugt Golden-Referenzdateien mit der EXAKTEN Logik von
wear_together_toolsuite.py @ HEAD (cff1227), nur ohne GUI.

Aufruf: python3 generate_reference.py <input.xlsx> <Kundenname> <Auftragsinfo> <Zielordner>
"""
import sys
import os
import math

import pandas as pd
import matplotlib
matplotlib.use("Agg")
import matplotlib.pyplot as plt
from matplotlib.backends.backend_pdf import PdfPages


def transform(df):
    df["Klasse"] = (
        df["Product Variation"]
        .astype(str)
        .str.split("|", n=4, expand=True)[2]
        .str.replace("Klasse:", "", regex=False)
    )
    df.rename(columns={"Item Name(löschen)": "Produktname", "Anzahl ": "Anzahl"}, inplace=True)
    df = pd.DataFrame(df.values.repeat(df["Anzahl"], axis=0), columns=df.columns)
    df.drop(["Anzahl", "Product Variation", "Bestellnotiz", "Bestellung Gesamtsumme(löschen)"], axis=1, inplace=True)
    size_order = pd.CategoricalDtype(["XS", "S", "M", "L", "XL", "XXL", "XXXL"], ordered=True)
    df["Größe"] = df["Größe"].astype(size_order)
    df.sort_values(by=["Klasse", "Produktname", "Farbe", "Größe"], inplace=True, ignore_index=True)
    col = "Individualisierungstext(zählt nur wenn Individualisierung Ja)"
    df[col] = df.apply(lambda x: x["Input Fields"] if x["Individualisierung"] == "Ja" else "", axis=1)
    df[col] = df[col].astype(str).str[50:].replace("nan", "").str.strip()
    df["⚠ Fehlender Individualisierungstext"] = (
        ((df["Individualisierung"] == "Ja") & (df[col] == "")).map({True: "TRUE", False: ""})
    )
    df["Karton"] = (df.index // 20) + 1
    df.drop(columns=["Input Fields"], inplace=True)
    df.sort_values(by=["Karton", "Klasse", "Produktname", "Farbe", "Größe"], inplace=True, ignore_index=True)
    df["Checkbox"] = "☐"
    df["Anzahl"] = 1
    df.columns = df.columns.astype(str)
    df["Individualisierung"] = df["Individualisierung"].astype(str)
    pivottable = df.pivot_table(
        index=["Produktname", "Farbe", "Größe"],
        values=["Anzahl", "Individualisierung"],
        aggfunc={"Anzahl": "count", "Individualisierung": lambda x: (x == "Ja").sum()},
        margins=True, margins_name="Grand Totals", observed=True,
    )
    pivottable = pivottable.rename(columns={"Anzahl": "Anzahl gesamt", "Individualisierung": "Davon Personalisierungen"})
    pivottable["Kartonnummer"] = ""
    pivottable["Ausschuss"] = ""
    pivottable["Anmerkungen"] = ""
    pivotlist = pivottable.reset_index()
    supplier_map = {
        "Schulpullover": "JH001", "Schulshirt": "B&C #E150", "Schulzoodie": "JH050",
        "Schuljacke": "JH043", "Schulsweater": "JH030", "Schulpolo": "B&C ID.001",
        "Sportshirt": "JC001", "Match-Polo": "JC021",
    }
    pivotlist["Produktname-Lieferant"] = pivotlist["Produktname"]
    pivotlist["Produktname-Lieferant"] = pivotlist["Produktname-Lieferant"].replace(supplier_map, regex=True)
    df.insert(0, "ID", range(1, len(df) + 1))
    return df, pivottable, pivotlist


def provision_ausrechnen(df):
    provision = 0.0
    for index in range(len(df)):
        if index >= 50:
            if index <= 99:
                provision += 0.5
            elif index <= 199:
                provision += 1
            elif index <= 299:
                provision += 1.25
            elif index <= 499:
                provision += 1.5
            else:
                provision += 2
    if provision < 20 and len(df) >= 50:
        provision = 20
    return provision


def write_excel(save_dir, ordername, orderinformation, provision, df, pivottable, pivotlist, reporttype):
    path = os.path.join(save_dir, f"{ordername}_orderreport_{reporttype}.xlsx")
    with pd.ExcelWriter(path, engine="openpyxl") as writer:
        pivottable.to_excel(writer, sheet_name="Übersicht_Tabelle")
        pivotlist.to_excel(writer, sheet_name="Übersicht_Liste", index=False)
        orders_df = df.copy()
        if reporttype != "internal":
            orders_df = orders_df.drop(columns=["⚠ Fehlender Individualisierungstext"])
        orders_df.to_excel(writer, sheet_name="Orders", index=False)

        def set_column_widths(ws, default_width=20):
            for column_cells in ws.columns:
                col_letter = column_cells[0].column_letter
                ws.column_dimensions[col_letter].width = default_width

        set_column_widths(writer.book["Orders"], default_width=20)
        set_column_widths(writer.book["Übersicht_Tabelle"], default_width=22)
        set_column_widths(writer.book["Übersicht_Liste"], default_width=22)
        sheetname = "Provisionsinformationen" if reporttype == "customer" else "Auftragsinformationen"
        ws = writer.book.create_sheet(sheetname)
        ws.cell(row=1, column=1, value=provision if reporttype == "customer" else orderinformation)


def dataframe_to_pdf(save_dir, ordername, df):
    pdf_path = os.path.join(save_dir, f"{ordername}_orderreport.pdf")
    with PdfPages(pdf_path) as pdf:
        nh = math.ceil(len(df) / 40)
        rows_per_page = len(df) // nh + 1
        for i in range(nh):
            pdf_columns_to_drop = [
                "⚠ Fehlender Individualisierungstext", "Order Total Amount without Tax",
                "Order Total Fee", "Order Line (w/o tax)", "Order Line Subtotal",
                "paypal fee", "Stripe fee",
            ]
            pdf_df = df.drop(columns=[c for c in pdf_columns_to_drop if c in df.columns])
            page = pdf_df.iloc[i * rows_per_page: min((i + 1) * rows_per_page, len(pdf_df))]
            fig, ax = plt.subplots(figsize=(11, 8.5))
            ax.axis("off")
            table = ax.table(cellText=page.values, colLabels=page.columns, loc="center")
            table.auto_set_font_size(False)
            table.set_fontsize(8)
            table.auto_set_column_width(col=list(range(len(page.columns))))
            header_color = "#b1dce4"
            id_column_color = "#b1dce4"
            row_colors = ["#ffffff", "#e4e4e4"]
            id_col_index = list(page.columns).index("ID")
            for (row, col), cell in table.get_celld().items():
                if row == 0:
                    cell.set_facecolor(header_color)
                    cell.set_text_props(weight="bold")
                elif col == id_col_index:
                    cell.set_facecolor(id_column_color)
                else:
                    cell.set_facecolor(row_colors[(row - 1) % 2])
            fig.text(0.5, 0.02, f"Seite {i + 1} von {nh}", ha="center", fontsize=8)
            pdf.savefig(fig, bbox_inches="tight")
            plt.close(fig)


def main():
    input_path, ordername, orderinformation, save_dir = sys.argv[1:5]
    os.makedirs(save_dir, exist_ok=True)
    df = pd.read_excel(input_path)
    df, pivottable, pivotlist = transform(df)
    provision = provision_ausrechnen(df)
    for reporttype in ("supplier", "internal", "customer"):
        write_excel(save_dir, ordername, orderinformation, provision, df, pivottable, pivotlist, reporttype)
    dataframe_to_pdf(save_dir, ordername, df)
    print(f"OK rows={len(df)} provision={provision}")


if __name__ == "__main__":
    main()
