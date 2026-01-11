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

        df["Individualisierungstext(zählt nur wenn Individualisierung Ja)"] = df.apply(
            lambda x: x["Input Fields"] if x["Individualisierung"] == "Ja" else "",
            axis=1
        )

        df["Individualisierungstext(zählt nur wenn Individualisierung Ja)"] = (
            df["Individualisierungstext(zählt nur wenn Individualisierung Ja)"]
            .astype(str)
            .str[50:]
            .replace("nan", "")
            .str.strip()
        )

        # =================================================
        # >>> GEÄNDERT: Prüfspalte für INTERNAL-Excel
        # =================================================
        df["⚠ Fehlender Individualisierungstext"] = (
            (
                (df["Individualisierung"] == "Ja") &
                (df["Individualisierungstext(zählt nur wenn Individualisierung Ja)"] == "")
            )
            .map({True: "TRUE", False: ""})
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
        pivottable["Kartonnummer"] = ""
        pivottable["Ausschuss"] = ""
        pivottable["Anmerkungen"] = ""
        pivotlist = pivottable.reset_index()
        supplier_map = {
            "Schulpullover": "JH001",
            "Schulshirt": "B&C #E150",
            "Schulzoodie": "JH050",
            "Schuljacke": "JH043",
            "Schulsweater": "JH030",
            "Schulpolo": "B&C ID.001",
            "Sportshirt": "JC001",
            "Match-Polo": "JC021"
        }

        pivotlist["Produktname-Lieferant"] = pivotlist["Produktname"]
        pivotlist["Produktname-Lieferant"] = pivotlist["Produktname-Lieferant"].replace(
            supplier_map,
            regex=True
        )
        self.df = df
        self.df.insert(0, "ID", range(1, len(df) + 1))
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
             # =================================================
            # >>> GEÄNDERT: Prüfspalte NUR im internal-Report
            # =================================================
            orders_df = df.copy()
            if reporttype != "internal":
                orders_df = orders_df.drop(columns=["⚠ Fehlender Individualisierungstext"])
            # =================================================
            orders_df.to_excel(writer, sheet_name="Orders", index=False)

            def set_column_widths(ws, default_width=20):
                for column_cells in ws.columns:
                    col_letter = column_cells[0].column_letter
                    ws.column_dimensions[col_letter].width = default_width

            ws_orders = writer.book["Orders"]
            set_column_widths(ws_orders, default_width=20)

            ws_uebersicht_tabelle = writer.book["Übersicht_Tabelle"]
            set_column_widths(ws_uebersicht_tabelle, default_width=22)

            ws_uebersicht_liste = writer.book["Übersicht_Liste"]
            set_column_widths(ws_uebersicht_liste, default_width=22)

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
                # >>> START PDF-SPALTENFILTER
                pdf_columns_to_drop = [
                    "⚠ Fehlender Individualisierungstext",
                    "Order Total Amount without Tax",
                    "Order Total Fee",
                    "Order Line (w/o tax)",
                    "Order Line Subtotal",
                    "paypal fee",
                    "Stripe fee"
                ]

                pdf_df = self.df.drop(
                    columns=[c for c in pdf_columns_to_drop if c in self.df.columns]
                )
                # >>> ENDE PDF-SPALTENFILTER
                page = pdf_df.iloc[
                i * rows_per_page : min((i + 1) * rows_per_page, len(pdf_df))
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
