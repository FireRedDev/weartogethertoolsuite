from tkinter import *
from tkinter import filedialog
from tkinter import messagebox
from openpyxl import load_workbook
import os
from openpyxl import load_workbook
from openpyxl import Workbook
from openpyxl.utils import get_column_letter
from openpyxl.utils.dataframe import dataframe_to_rows
import pandas as pd
import traceback
def openFile():
    tf = filedialog.askopenfilename(
        initialdir="C:/Users/MainFrame/Desktop/", 
        title="Open Excel file", 
        filetypes=[("Excel files", ".xlsx .xltx")]
        )
    pathh.insert(END, tf)
    print(tf)
    
  
    df = pd.read_excel(tf)
    df["Klasse"] = df["Product Variation"].str.split("|",n=4,expand=True)[2].str.replace("Klasse:","")
    df.rename(columns={"Item Name(löschen)" : "Produktname", "Anzahl ":"Anzahl"}, inplace=True)
    print(df)
    print(df.columns)

    pd.set_option('display.max_columns', 100)  # or 1000
    pd.set_option('display.max_rows', 100)  # or 1000
    pd.set_option('display.max_colwidth', 100)
    df = pd.DataFrame(df.values.repeat(df.Anzahl, axis=0), columns=df.columns)
    df.drop(['Anzahl','Product Variation','Bestellnotiz', 'Bestellung Gesamtsumme(löschen)'], axis=1, inplace=True)
    print(df.to_string)
    t = pd.CategoricalDtype(categories=['XS', 'S','M','L','XL','XXL','XXXL'], ordered=True)
    df['Größe']=pd.Series(df.Größe, dtype=t)
    df.sort_values(by=['Klasse','Produktname','Farbe','Größe'], inplace=True,ignore_index=True)
    #=WENN(UND(I2="Ja";J2="");D2;WENN(I2="Nein";"";WENNFEHLER(RECHTS(J2;LÄNGE(J2)-50);"")))
    df["Individualisierungstext(zählt nur wenn Individualisierung Ja)"] = df.apply(lambda x: x['Input Fields'] if x['Individualisierung']=='Ja' else "", axis=1)
    #df["Individualisierungstext(zählt nur wenn Individualisierung Ja)"] = np.where((~df['Input Fields'].isnull()) & (~df['Individualisierung']== 'Ja') ,df['Nachnahme (Rechnungsadresse)'],"")
    df["Individualisierungstext(zählt nur wenn Individualisierung Ja)"] = df["Individualisierungstext(zählt nur wenn Individualisierung Ja)"].str[50:]
    df["Individualisierungstext(zählt nur wenn Individualisierung Ja)"] = df.apply(lambda x: x['Nachnahme (Rechnungsadresse)'] if pd.isnull(x['Individualisierungstext(zählt nur wenn Individualisierung Ja)']) else x['Individualisierungstext(zählt nur wenn Individualisierung Ja)'], axis=1)
    df["Karton"] = (df.index / 20 + 1).astype(int)
    df.drop(['Input Fields'], axis=1, inplace=True)
    
    df.sort_values(by=['Karton', 'Klasse','Produktname','Farbe','Größe'], inplace=True,ignore_index=True)
    df['Checkbox']='☐'
    df['Unterschrift']=' '
    print(df.to_string)

    df["Anzahl"]=1
    #df2= df2.pivot_table(index=['Produktname','Größe','Farbe'], 
                # columns='Individualisierung', 
                # margins = True,
                # aggfunc='size', 
                # fill_value=0)
   
    #wb = load_workbook(filename = 'vorlage_bestellliste_shop.xltx')
    #ws = wb["Orders"]
    #bestellungen = ws.tables["Bestellungen"]
    #print(bestellungen)
    
    df.columns = df.columns.astype(str)

    def createpdf(df):
        try:
            import matplotlib.pyplot as plt
            from matplotlib.backends.backend_pdf import PdfPages
            #https://stackoverflow.com/questions/32137396/how-do-i-plot-only-a-table-in-matplotlib
            fig, ax =plt.subplots(figsize=(12,4))
            ax.axis('tight')
            ax.axis('off')
            the_table = ax.table(cellText=df.values,colLabels=df.columns,loc='center')
            
            [t.auto_set_font_size(False) for t in [the_table]]
            [t.set_fontsize(8) for t in [the_table]]

            the_table.auto_set_column_width(col=list(range(len(df.columns)))) # Provide integer list of columns to adjust
            #https://stackoverflow.com/questions/4042192/reduce-left-and-right-margins-in-matplotlib-plot
            pp = PdfPages("bestellliste.pdf")
            pp.savefig(fig, bbox_inches='tight')
            pp.close()    
        except:
            print("Exception occurred when creating a pdf")
            traceback.print_exc()   

    def append_df_to_excel(filename, df, sheet_name='Sheet1', startrow=None,
                        truncate_sheet=False, 
                        **to_excel_kwargs):
        """
        Append a DataFrame [df] to existing Excel file [filename]
        into [sheet_name] Sheet.
        If [filename] doesn't exist, then this function will create it.

        @param filename: File path or existing ExcelWriter
                        (Example: '/path/to/file.xlsx')
        @param df: DataFrame to save to workbook
        @param sheet_name: Name of sheet which will contain DataFrame.
                        (default: 'Sheet1')
        @param startrow: upper left cell row to dump data frame.
                        Per default (startrow=None) calculate the last row
                        in the existing DF and write to the next row...
        @param truncate_sheet: truncate (remove and recreate) [sheet_name]
                            before writing DataFrame to Excel file
        @param to_excel_kwargs: arguments which will be passed to `DataFrame.to_excel()`
                                [can be a dictionary]
        @return: None

        Usage examples:

        >>> append_df_to_excel('d:/temp/test.xlsx', df)

        >>> append_df_to_excel('d:/temp/test.xlsx', df, header=None, index=False)

        >>> append_df_to_excel('d:/temp/test.xlsx', df, sheet_name='Sheet2',
                            index=False)

        >>> append_df_to_excel('d:/temp/test.xlsx', df, sheet_name='Sheet2', 
                            index=False, startrow=25)

        (c) [MaxU](https://stackoverflow.com/users/5741205/maxu?tab=profile)
        """
        # Excel file doesn't exist - saving and exiting
        if not os.path.isfile(filename):
            df.to_excel(
                filename,
                sheet_name=sheet_name, 
                startrow=startrow if startrow is not None else 0, 
                **to_excel_kwargs)
            return
        
        # ignore [engine] parameter if it was passed
        if 'engine' in to_excel_kwargs:
            to_excel_kwargs.pop('engine')

        writer = pd.ExcelWriter(filename, engine='openpyxl', mode='a')

        # try to open an existing workbook
        writer.book = load_workbook(filename)
        

        # get the last row in the existing Excel sheet
        # if it was not specified explicitly
        if startrow is None and sheet_name in writer.book.sheetnames:
            startrow = writer.book[sheet_name].max_row
        # truncate sheet
        if truncate_sheet and sheet_name in writer.book.sheetnames:
            # index of [sheet_name] sheet
            idx = writer.book.sheetnames.index(sheet_name)
            # remove [sheet_name]
            writer.book.remove(writer.book.worksheets[idx])
            # create an empty sheet [sheet_name] using old index
            writer.book.create_sheet(sheet_name, idx)
        maxTableRow = str(df[df.columns[0]].count()+1)
        print(maxTableRow)
        ws = writer.book["Orders"]
        alltables = ws.tables
        alltables.get(name="Bestellungen").ref = "A1:K" + maxTableRow
        #for table in alltables:
        #    print(table)
        #    if table.displayName == "Bestellungen":
        #        table.ref = "A1:L{maxTableRow}"
        # copy existing sheets
        writer.sheets = {ws.title:ws for ws in writer.book.worksheets}

        if startrow is None:
            startrow = 0
        
    

        # write out the new sheet
        df.to_excel(writer, sheet_name, startrow=0, **to_excel_kwargs)

        # save the workbook
        writer.save()
        writer.close()
    import shutil
    shutil.copy("vorlage_bestellliste_shop1.xlsx", "result_orderlist.xlsx")
    try:
        append_df_to_excel('result_orderlist.xlsx', df, header = True, index=False, sheet_name ='Orders')
        messagebox.showinfo('showinfo','Sucessfully created documents')
    except:
        messagebox.showerror('showerror','Error during generating XLSX')
        traceback.print_exc()
    
    createpdf(df)
    try:
        ws.destroy()
    except:
        messagebox.showerror('showerror','Error closing GUI')

    

ws = Tk()
ws.title("Wear Together Toolsuite")
ws.geometry("400x450")
ws['bg']='#fb0'

txtarea = Text(ws, width=40, height=20)
txtarea.pack(pady=20)

pathh = Entry(ws)
pathh.pack(side=LEFT, expand=True, fill=X, padx=20)



Button(
    ws, 
    text="Open File", 
    command=openFile
    ).pack(side=RIGHT, expand=True, fill=X, padx=20)


ws.mainloop()