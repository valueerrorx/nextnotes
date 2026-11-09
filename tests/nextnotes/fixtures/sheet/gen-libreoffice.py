# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# M8b Done 3: Referenzwerte mit LibreOffice Calc erzeugen. Aufruf: python3 gen-libreoffice.py <arbeitsordner> > functions.json
# (danach UND/ODER/NICHT als Wahrheitswerte, CSV liefert 1/0; siehe _doc in functions.json)
import json, subprocess, os, sys, csv
S=sys.argv[1]
A=[2,4,4,5,7,9,10,12,15,20]
B=[1.5,3.1,4.2,4.8,7.4,8.9,10.2,12.8,14.1,19.6]
C=["rot",3,"grün","rot",None,"blau","ja",None,1.5,"x"]
# (de, en) – de: Semikolon/Komma, en: Komma/Punkt
F=[
("=SUMME(A1:A10)","=SUM(A1:A10)"),
("=MITTELWERT(A1:A10)","=AVERAGE(A1:A10)"),
("=MIN(B1:B10)","=MIN(B1:B10)"),
("=MAX(B1:B10)","=MAX(B1:B10)"),
("=ANZAHL(A1:C10)","=COUNT(A1:C10)"),
("=ANZAHL2(A1:C10)","=COUNTA(A1:C10)"),
("=ZÄHLENWENN(A1:A10;\">5\")","=COUNTIF(A1:A10,\">5\")"),
("=SUMMEWENN(A1:A10;\">5\";B1:B10)","=SUMIF(A1:A10,\">5\",B1:B10)"),
("=RUNDEN(B3/3;2)","=ROUND(B3/3,2)"),
("=AUFRUNDEN(2,341;1)","=ROUNDUP(2.341,1)"),
("=ABRUNDEN(-2,349;2)","=ROUNDDOWN(-2.349,2)"),
("=GANZZAHL(-3,7)","=INT(-3.7)"),
("=ABS(-4,5)","=ABS(-4.5)"),
("=REST(17;5)","=MOD(17,5)"),
("=WURZEL(2)","=SQRT(2)"),
("=POTENZ(2;10)","=POWER(2,10)"),
("=PI()","=PI()"),
("=EXP(1,5)","=EXP(1.5)"),
("=LN(10)","=LN(10)"),
("=LOG(8;2)","=LOG(8,2)"),
("=LOG10(250)","=LOG10(250)"),
("=SIN(1)","=SIN(1)"),
("=COS(1)","=COS(1)"),
("=TAN(1)","=TAN(1)"),
("=ARCSIN(0,5)","=ASIN(0.5)"),
("=ARCCOS(0,3)","=ACOS(0.3)"),
("=ARCTAN(2)","=ATAN(2)"),
("=BOGENMASS(30)","=RADIANS(30)"),
("=GRAD(1)","=DEGREES(1)"),
("=WENN(A1>1;\"ja\";\"nein\")","=IF(A1>1,\"ja\",\"nein\")"),
("=UND(A1>1;B1<2)","=AND(A1>1,B1<2)"),
("=ODER(A1>5;B1>5)","=OR(A1>5,B1>5)"),
("=NICHT(A1>5)","=NOT(A1>5)"),
("=MEDIAN(A1:A10)","=MEDIAN(A1:A10)"),
("=MODUS.EINF(A1:A10)","=MODE.SNGL(A1:A10)"),
("=STABW.S(B1:B10)","=STDEV.S(B1:B10)"),
("=STABW.N(B1:B10)","=STDEV.P(B1:B10)"),
("=VAR.S(B1:B10)","=VAR.S(B1:B10)"),
("=VAR.P(B1:B10)","=VAR.P(B1:B10)"),
("=QUARTILE.INKL(A1:A10;1)","=QUARTILE.INC(A1:A10,1)"),
("=KORREL(A1:A10;B1:B10)","=CORREL(A1:A10,B1:B10)"),
("=STEIGUNG(B1:B10;A1:A10)","=SLOPE(B1:B10,A1:A10)"),
("=ACHSENABSCHNITT(B1:B10;A1:A10)","=INTERCEPT(B1:B10,A1:A10)"),
("=BINOM.VERT(3;10;0,5;0)","=BINOM.DIST(3,10,0.5,0)"),
("=NORM.VERT(1;0;1;1)","=NORM.DIST(1,0,1,1)"),
("=NORM.INV(0,95;0;1)","=NORM.INV(0.95,0,1)"),
]
rows=[]
for i in range(max(10,len(F))):
    r=['','','','','']
    if i<10:
        r[0]=str(A[i]); r[1]=str(B[i]); c=C[i]
        r[2]='' if c is None else ('TRUE' if c is True else str(c))
    if i<len(F): r[4]=F[i][1]
    rows.append(r)
with open(S+'/in.csv','w') as f:
    for r in rows: f.write('\t'.join(r)+'\n')
subprocess.run(['soffice','--headless','--infilter=CSV:9,34,76,1,,1033,false,true,false,false,false,,true','--convert-to','csv:Text - txt - csv (StarCalc):9,34,76,1,,1033,false,true,false,false','--outdir',S+'/out',S+'/in.csv'],check=True,capture_output=True)
out=[l.rstrip('\n').split('\t') for l in open(S+'/out/in.csv')]
cases=[]
for i,(de,en) in enumerate(F):
    v=out[i][4].strip('"')
    try: val=float(v)
    except: val={'TRUE':True,'FALSE':False}.get(v,v)
    cases.append({'cell':'E%d'%(i+1),'de':de,'lo':en,'expected':val})
data=[[A[i],B[i],C[i]] for i in range(10)]
print(json.dumps({'data':data,'cases':cases},ensure_ascii=False,indent=1))
