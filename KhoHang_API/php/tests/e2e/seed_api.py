import requests, sys
B="http://127.0.0.1:8000"
t=requests.post(B+"/auth/login",json={"username_or_email":"admin","password":"admin123"}).json()["access_token"]
H={"Authorization":"Bearer "+t}
def p(m,u,**k):
    r=requests.request(m,B+u,headers={**H,**k.pop("headers",{})},**k); print(m,u,r.status_code); return r
p("POST","/suppliers",json={"name":"NCC Ánh Dương","phone":"0909"})
for it in [("Giấy A4","A4","ram",65000,"VPP",5),("Bút bi","BB","cây",5000,"VPP",50),("Chuột","MS","cái",120000,"Điện tử",3)]:
    p("POST","/items",json=dict(zip(["name","sku","unit","price","category","min_stock"],it)))
p("POST","/warehouses",json={"name":"Kho chính","code":"K1","managers":[{"email":"admin@example.com","position":"Chủ kho"}]})
p("POST","/stock/in",json={"warehouse_code":"K1","supplier":"NCC Ánh Dương","date":"2026-11-20","items":[
 {"item_id":"1","item_code":"A4","item_name":"Giấy A4","quantity":20,"unit":"ram","price":60000},
 {"item_id":"2","item_code":"BB","item_name":"Bút bi","quantity":30,"unit":"cây","price":4000}]})
p("POST","/stock/out",json={"warehouse_code":"K1","recipient":"Khách","purpose":"Bán hàng","date":"2026-11-22","items":[
 {"item_id":"1","item_code":"A4","item_name":"Giấy A4","quantity":18,"unit":"ram","sell_price":70000}]})
