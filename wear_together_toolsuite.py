from tkinter import *
from tkinter import filedialog, messagebox, simpledialog
import pandas as pd
import openpyxl
import os
import math
import traceback
import matplotlib.pyplot as plt
from matplotlib.backends.backend_pdf import PdfPages
from pandastable import Table


class App(Tk):

    # =================================================
    # GUI / Init
    # =================================================
    def __init__(self):
        super().__init__()

        self.title("Wear Together Toolsuite")
        self.geometry("400x450")
        self["bg"] = "#fb0"

        self.txtarea = Text(self, width=40, height=20)
        self.txtarea.pack(pady=20)

        self.pathh = Entry(self)
        self.pathh.pack(side=LEFT, expand=True, fill=X, padx=20)

        Button(
            self,
            text="Open File",
            command=self.handleFileSelected
        ).pack(side=RIGHT, expand=True, fill=X, padx=20)

        self.appendToLog("Wear Together Toolsuite gestartet")

    def appendToLog(self, text):
        self.txtarea.insert(END, text + "\n")
        self.txtarea.see(END)
        self.update_idletasks()

    # =================================================
    # Datei laden
    # =================================================
    def loadDfFromExcel(self):
        file = filedialog.askopenfilename(
            title="Open Excel file",
            filetypes=[("Excel files", "*.xlsx *.xltx")]
        )
        if not file:
            return None

        self.pathh.delete(0, END)
        self.pathh.insert(END, file)
        return pd.read_excel(file)

    # =================================================
    # Hauptablauf
    # =================================================
    def handleFileSelected(self):
        try:
            self.df = self.loadDfFromExcel()
            if self.df is None:
                return

            self.appendToLog("Excel geladen")

            self.ordername = simpledialog.askstring(
                "Kundenname",
                "Bitte Name der Schule/Organisation eingeben"
            )

            orderinformation = simpledialog.askstring(
                "Bestellinformationen",
                "Informationen für den Lieferanten"
            )

            self.saveToDirectory = filedialog.askdirectory(
                title="Zielordner auswählen"
            )

            self.appendToLog(f"Zielordner: {self.saveToDirectory}")

            pivottable, pivotlist = self.transformData()
            self.appendToLog("Daten transformiert")

            self.provision_ausrechnen()

            self.writeToExcel(orderinformation, self.df, pivottable, pivotlist, "supplier")
            self.writeToExcel(orderinformation, self.df, pivottable, pivotlist, "internal")
            self.writeToExcel(orderinformation, self.df, pivottable, pivotlist, "customer")

            self.appendToLog("Excel-Dateien erstellt")

            self.dataframe_to_pdf()
            self.appendToLog("PDF erstellt")

            preview = Toplevel(self)
            preview.title("Datenvorschau")
            frame = Frame(preview)
            frame.pack(fill=BOTH, expand=1)
            preview_df = self.df.copy()

            for col in preview_df.select_dtypes(include=["category"]).columns:
                preview_df[col] = preview_df[col].astype(str)

            Table(frame, dataframe=preview_df, showtoolbar=True).show()


        except Exception:
            traceback.print_exc()
            messagebox.showerror("Fehler", "Fehler bei der Verarbeitung")

    # =================================================
    # Daten-Transformation (INHALTLICH WIE ALTVERSION)
    # =================================================
    def transformData(self):
        df = self.df

        df["Klasse"] = (
            df["Product Variation"]
            .astype(str)
            .str.split("|", n=4, expand=True)[2]
            .str.replace("Klasse:", "", regex=False)
        )

        df.rename(
            columns={
                "Item Name(löschen)": "Produktname",
                "Anzahl ": "Anzahl"
            },
            inplace=True
        )

        df = pd.DataFrame(
            df.values.repeat(df["Anzahl"], axis=0),
            columns=df.columns
        )

        df.drop(
            ["Anzahl", "Product Variation", "Bestellnotiz", "Bestellung Gesamtsumme(löschen)"],
            axis=1,
            inplace=True
        )

        size_order = pd.CategoricalDtype(
            ["XS", "S", "M", "L", "XL", "XXL", "XXXL"],
            ordered=True
        )
        df["Größe"] = df["Größe"].astype(size_order)

        df.sort_values(
            by=["Klasse", "Produktname", "Farbe", "Größe"],
            inplace=True,
            ignore_index=True
        )

        # =================================================
        # Individualisierungstext – 1:1 wie ALTVERSION
        # =================================================
        df["Individualisierungstext(zählt nur wenn Individualisierung Ja)"] = df.apply(
            lambda x: x["Input Fields"] if x["Individualisierung"] == "Ja" else "",
            axis=1
        )

        df["Individualisierungstext(zählt nur wenn Individualisierung Ja)"] = (
            df["Individualisierungstext(zählt nur wenn Individualisierung Ja)"]
            .astype(str)
            .str[50:]
        )

        df["Individualisierungstext(zählt nur wenn Individualisierung Ja)"] = df.apply(
            lambda x: x["Nachnahme (Rechnungsadresse)"]
            if pd.isna(x["Individualisierungstext(zählt nur wenn Individualisierung Ja)"])
               or x["Individualisierungstext(zählt nur wenn Individualisierung Ja)"] in ("", "nan")
            else x["Individualisierungstext(zählt nur wenn Individualisierung Ja)"],
            axis=1
        )
        # =================================================

        df["Karton"] = (df.index // 20) + 1
        df.drop(columns=["Input Fields"], inplace=True)

        df.sort_values(
            by=["Karton", "Klasse", "Produktname", "Farbe", "Größe"],
            inplace=True,
            ignore_index=True
        )

        df["Checkbox"] = "☐"
        df["Anzahl"] = 1

        df.columns = df.columns.astype(str)
        df["Individualisierung"] = df["Individualisierung"].astype(str)
        pivottable = df.pivot_table(
            index=["Produktname", "Farbe", "Größe"],
            values=["Anzahl", "Individualisierung"],
            aggfunc={
                "Anzahl": "count",
                "Individualisierung": lambda x: (x == "Ja").sum()
            },
            margins=True,
            margins_name="Grand Totals",
            observed=True
        )

        pivottable = pivottable.rename(
            columns={
                "Anzahl": "Anzahl gesamt",
                "Individualisierung": "Davon Personalisierungen"
            }
        )

        pivotlist = pivottable.reset_index()

        self.df = df
        return pivottable, pivotlist

    # =================================================
    # Excel schreiben
    # =================================================
    def writeToExcel(self, orderinformation, df, pivottable, pivotlist, reporttype):
        path = os.path.join(
            self.saveToDirectory,
            f"{self.ordername}_orderreport_{reporttype}.xlsx"
        )

        with pd.ExcelWriter(path, engine="openpyxl") as writer:
            pivottable.to_excel(writer, sheet_name="Übersicht_Tabelle")
            pivotlist.to_excel(writer, sheet_name="Übersicht_Liste", index=False)
            df.to_excel(writer, sheet_name="Orders", index=False)

            sheetname = (
                "Provisionsinformationen"
                if reporttype == "customer"
                else "Auftragsinformationen"
            )

            ws = writer.book.create_sheet(sheetname)
            ws.cell(row=1, column=1, value=self.provision if reporttype == "customer" else orderinformation)

    # =================================================
    # Provision
    # =================================================
    def provision_ausrechnen(self):
        provision = 0.0
        for index in range(len(self.df)):
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
        if provision < 20 and len(self.df) >= 50:
            provision = 20
        self.provision = provision

    # =================================================
    # PDF – ORIGINALLOGIK (nur stabilisiert)
    # =================================================
    def dataframe_to_pdf(self):
        pdf_path = os.path.join(
            self.saveToDirectory,
            f"{self.ordername}_orderreport.pdf"
        )

        with PdfPages(pdf_path) as pdf:
            nh = math.ceil(len(self.df) / 40)
            rows_per_page = len(self.df) // nh + 1

            for i in range(nh):
                page = self.df.iloc[
                    i * rows_per_page : min((i + 1) * rows_per_page, len(self.df))
                ]

                fig, ax = plt.subplots(figsize=(11, 8.5))
                ax.axis("off")

                table = ax.table(
                    cellText=page.values,
                    colLabels=page.columns,
                    loc="center"
                )

                table.auto_set_font_size(False)
                table.set_fontsize(8)
                table.auto_set_column_width(col=list(range(len(page.columns))))

                fig.text(
                    0.5,
                    0.02,
                    f"Seite {i + 1} von {nh}",
                    ha="center",
                    fontsize=8
                )

                pdf.savefig(fig, bbox_inches="tight")
                plt.close(fig)


if __name__ == "__main__":
    app = App()
    app.mainloop()
