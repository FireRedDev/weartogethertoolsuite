
from tkinter import *
from tkinter import filedialog
from tkinter import messagebox
from tkinter import simpledialog
from openpyxl import load_workbook
import os
from typing import NoReturn
from openpyxl import load_workbook
import openpyxl as openpyxl
from openpyxl import Workbook
from openpyxl.utils import get_column_letter
from openpyxl.utils.dataframe import dataframe_to_rows
import pandas as pd
import traceback
import sys
import matplotlib.pyplot as plt
from matplotlib.backends.backend_pdf import PdfPages
from pretty_html_table import build_table
from pandastable import Table, TableModel, config
import shutil
import math
class App(Tk):
    def loadDfFromExcel(self):
        file = filedialog.askopenfilename(
            initialdir="C:/Users/MainFrame/Desktop/", 
            title="Open Excel file", 
            filetypes=[("Excel files", ".xlsx .xltx")]
            )
        self.pathh.insert(END, file)
        return pd.read_excel(file)

    def createHTML(self,pivottableastable, pivottableaslist):
        utf8='<head><meta charset="utf-8"></head>'
        html = build_table(self.df, 'blue_light')
        #pivottableastable=build_table(pivottableastable, 'blue_light')
        pivottableastable= pivottableastable.to_html()
        pivottableaslist=build_table(pivottableaslist, 'blue_light')

    def writeToExcel(self, orderinformation, df, pivottableastable, pivottableaslist,reporttype):
        try:
            with pd.ExcelWriter(os.path.join(self.saveToDirectory, self.ordername+"_orderreport_"+reporttype + '.' + "xlsx"), engine='openpyxl') as writer:
                pivottableastable.to_excel(writer, sheet_name='Übersicht_Tabelle')
                if(reporttype!='customer'):
                    pivottableaslist.to_excel(writer, sheet_name='Übersicht_Liste')
                    df.to_excel(writer, sheet_name='Orders')
                    text_sheet = writer.book.create_sheet(title='Auftragsinformationen')
                    text_sheet.cell(column=1, row=1, value=orderinformation)
                    
                
                
                
                def columns_best_fit(ws: openpyxl.worksheet.worksheet.Worksheet) -> NoReturn:
                    column_letters = tuple(openpyxl.utils.get_column_letter(col_number + 1) for col_number in range(ws.max_column))
                    for column_letter in column_letters:
                        ws.column_dimensions[column_letter].width = 15
                        ws.column_dimensions[column_letter].bestFit = True
                def columns_setWidth(ws: openpyxl.worksheet.worksheet.Worksheet, width) -> NoReturn:
                    column_letters = tuple(openpyxl.utils.get_column_letter(col_number + 1) for col_number in range(ws.max_column))
                    for column_letter in column_letters:
                        ws.column_dimensions[column_letter].width = width

                
                columns_setWidth(writer.sheets['Übersicht_Tabelle'],20)
                
                if(reporttype!='customer'):
                    columns_setWidth(writer.sheets['Übersicht_Liste'],20)
                    columns_best_fit(writer.sheets['Orders'])
                    openpyxl.worksheet.worksheet.Worksheet.set_printer_settings(writer.sheets['Orders'],
                    paper_size =writer.sheets['Orders'].PAPERSIZE_A4, orientation=writer.sheets['Orders'].ORIENTATION_LANDSCAPE)
                    writer.sheets['Orders'].sheet_properties.pageSetUpPr.fitToPage = True
                    writer.sheets['Orders'].page_setup.fitToHeight = False
        except: 
            self.appendToLog("Fehler beim Schreiben in die Excel")
            raise

           
    def transformData(self):
        self.df["Klasse"] = self.df["Product Variation"].str.split("|",n=4,expand=True)[2].str.replace("Klasse:","")
        self.df.rename(columns={"Item Name(löschen)" : "Produktname", "Anzahl ":"Anzahl"}, inplace=True)

        pd.set_option('display.max_columns', 100)  # or 1000
        pd.set_option('display.max_rows', 100)  # or 1000
        pd.set_option('display.max_colwidth', 100)
        self.df = pd.DataFrame(self.df.values.repeat(self.df.Anzahl, axis=0), columns=self.df.columns)
        self.df.drop(['Anzahl','Product Variation','Bestellnotiz', 'Bestellung Gesamtsumme(löschen)'], axis=1, inplace=True)
        
        t = pd.CategoricalDtype(categories=['XS', 'S','M','L','XL','XXL','XXXL'], ordered=True)
        self.df['Größe']=pd.Series(self.df.Größe, dtype=t)
        self.df.sort_values(by=['Klasse','Produktname','Farbe','Größe'], inplace=True,ignore_index=True)
        #=WENN(UND(I2="Ja";J2="");D2;WENN(I2="Nein";"";WENNFEHLER(RECHTS(J2;LÄNGE(J2)-50);"")))
        self.df["Individualisierungstext(zählt nur wenn Individualisierung Ja)"] = self.df.apply(lambda x: x['Input Fields'] if x['Individualisierung']=='Ja' else "", axis=1)
        #self.df["Individualisierungstext(zählt nur wenn Individualisierung Ja)"] = np.where((~self.df['Input Fields'].isnull()) & (~self.df['Individualisierung']== 'Ja') ,self.df['Nachnahme (Rechnungsadresse)'],"")
        self.df["Individualisierungstext(zählt nur wenn Individualisierung Ja)"] = self.df["Individualisierungstext(zählt nur wenn Individualisierung Ja)"].str[50:]
        self.df["Individualisierungstext(zählt nur wenn Individualisierung Ja)"] = self.df.apply(lambda x: x['Nachnahme (Rechnungsadresse)'] if pd.isnull(x['Individualisierungstext(zählt nur wenn Individualisierung Ja)']) else x['Individualisierungstext(zählt nur wenn Individualisierung Ja)'], axis=1)
        self.df["Karton"] = (self.df.index / 20 + 1).astype(int)
        self.df.drop(['Input Fields'], axis=1, inplace=True)
        
        self.df.sort_values(by=['Karton', 'Klasse','Produktname','Farbe','Größe'], inplace=True,ignore_index=True)
        self.df['Checkbox']='☐'
        self.df['Unterschrift']=' '
        

        self.df["Anzahl"]=1
        #self.df2= self.df2.pivot_table(index=['Produktname','Größe','Farbe'], 
                    # columns='Individualisierung', 
                    # margins = True,
                    # aggfunc='size', 
                    # fill_value=0)
    
        #wb = load_workbook(filename = 'vorlage_bestellliste_shop.xltx')
        #self = wb["Orders"]
        #bestellungen = self.tables["Bestellungen"]
        #print(bestellungen)
        
        self.df.columns = self.df.columns.astype(str)
        pd.options.display.float_format = '{:,.0f}'.format
        pivottableastable = self.df.pivot_table(
        index=["Produktname","Farbe","Größe"], values=["Anzahl","Individualisierung"], aggfunc={'Anzahl':len,'Individualisierung':(lambda x:(x=='Ja').sum())}, margins=True, margins_name='Grand Totals')
        
        pivottableastable = pivottableastable.rename(columns={'Individualisierung': 'Davon Personalisierungen', 'Anzahl': 'Anzahl gesamt'})
        
        pivottableaslist = pd.DataFrame(pivottableastable.to_records())
        key = {"Schulpullover": "JH001",
        "Schulshirt": "B&C001",
        "Schulzoodie" : "JH050",
        "Schuljacke" : "JH043",
        "Schulsweater" : "JH030",
        "Schulshirt" : "BCTU01T",
        "Schulpolo" : "BCPUI10",
        "Sportshirt" : "JC001",
        "Match-Polo" : "JC021",
        "Schulhemnd" : "JC021"
        }
        pivottableaslist['Produktname-Lieferant']=pivottableaslist['Produktname']
        pivottableaslist.replace({"Produktname-Lieferant": key}, regex=True, inplace = True)
        return pivottableastable,pivottableaslist
    def __init__(self):
            super().__init__()

            # configure the root window
            #self.title('My Awesome App')
            #self.geometry('300x50')

            # label
            #self.label = ttk.Label(self, text='Hello, Tkinter!')
            #self.label.pack()

            # button
            #self.button = ttk.Button(self, text='Click Me')
            #self.button['command'] = self.button_clicked
            #self.button.pack()
            
            self.title("Wear Together Toolsuite")
            self.geometry("400x450")
            self['bg']='#fb0'

            self.txtarea = Text(self, width=40, height=20)
            self.txtarea.pack(pady=20)
            self.appendToLog("wear Together Toolsuite gestartet. Bitte eine Excel im Format eines Webshop-Export-CSV auswählen")
            self.pathh = Entry(self)
            self.pathh.pack(side=LEFT, expand=True, fill=X, padx=20)
            Button(
                self, 
                text="Open File", 
                command=self.handleFileSelected
                ).pack(side=RIGHT, expand=True, fill=X, padx=20)
    def appendToLog(self,text):
        self.txtarea.insert(1.0,'\n'+'-------'+ '\n')
        self.txtarea.insert(1.0,'\n'+text+ '\n')

    def handleFileSelected(self):
            self.df = self.loadDfFromExcel()
            self.appendToLog("Excel geladen")
            self.ordername = simpledialog.askstring("Kundenname", "Bitte gib den Namen der Kundenschule/Organisation ein für die Dateinamen")
            orderinformation = simpledialog.askstring("Bestellinformationen", "Bitte gib die Informationen für den Lieferanten ein")
            self.appendToLog("Infos für Lieferanten geladen")
            self.saveToDirectory = filedialog.askdirectory(title="Auswählen, in welchem Ordner die Orderreports gespeichert werden sollen")
            self.appendToLog("Speichere Dateien in "+self.saveToDirectory)
            pivottableastable, pivottableaslist = self.transformData()
            self.appendToLog("Daten Transformiert")
            dfForExcel = self.df[['Produktname', 'Karton','Größe','Farbe','Individualisierung','Individualisierungstext(zählt nur wenn Individualisierung Ja)', 'Checkbox', 'Anzahl']]
            self.writeToExcel(orderinformation, dfForExcel, pivottableastable, pivottableaslist, 'supplier') 
            self.writeToExcel(orderinformation, self.df, pivottableastable, pivottableaslist, 'internal') 
            self.writeToExcel(orderinformation, self.df, pivottableastable, pivottableaslist, 'customer') 
            self.appendToLog("Daten in Excel geschrieben")   
            self.createHTML(pivottableastable, pivottableaslist)
            self.appendToLog("Creating PDF")
            self.dataframe_to_pdf()
            self.appendToLog("PDF erstellt")
            newWindow = Toplevel(self)
        
            # sets the title of the
            # Toplevel widget
            newWindow.title("GUI Editor (Änderungen sind nicht automatisch in Excel)")
        
            # sets the geometry of toplevel
            newWindow.geometry("200x200")
            f = Frame(newWindow)
            f.pack(fill=BOTH,expand=1)
            pt = Table(f,dataframe=self.df,
                                            showtoolbar=True, shoselftatusbar=True)
            pt.show()
            
    def _draw_as_table(self,df, pagesize):
        alternating_colors = [['white'] * len(df.columns), ['lightgray'] * len(df.columns)] * len(df)
        alternating_colors = alternating_colors[:len(df)]
        fig, ax = plt.subplots(figsize=pagesize)
        ax.axis('tight')
        ax.axis('off')
        the_table = ax.table(cellText=df.values,
                            rowLabels=df.index,
                            colLabels=df.columns,
                            rowColours=['lightblue']*len(df),
                            colColours=['lightblue']*len(df.columns),
                            cellColours=alternating_colors,
                            loc='center')
        [t.auto_set_font_size(False) for t in [the_table]]
        [t.set_fontsize(8) for t in [the_table]]
        the_table.auto_set_column_width(col=list(range(len(self.df.columns)))) # Provide integer list of columns to adjust
        return fig
  

    def dataframe_to_pdf(self, numpages=(1, 1), pagesize=(11, 8.5)):
        with PdfPages(os.path.join(self.saveToDirectory, self.ordername+"_orderreport" + '.' + "pdf")) as pdf:
            nh, nv = numpages
            nh = math.ceil(len(self.df)/40)
            rows_per_page = len(self.df) // nh
            cols_per_page = len(self.df.columns) // nv
            for i in range(0, nh):
                for j in range(0, nv):
                    page = self.df.iloc[(i*rows_per_page):min((i+1)*rows_per_page, len(self.df)),
                                (j*cols_per_page):min((j+1)*cols_per_page, len(self.df.columns))]
                    fig = self._draw_as_table(page, pagesize)
                    if nh > 1 or nv > 1:
                        # Add a part/page number at bottom-center of page
                        fig.text(0.5, 0.5/pagesize[0],
                                "Part-{}x{}: Page-{}".format(i+1, j+1, i*nv + j + 1),
                                ha='center', fontsize=8)
                    pdf.savefig(fig, bbox_inches='tight')
                    
                    plt.close()
                
if __name__ == "__main__":
  app = App()
  app.mainloop()
