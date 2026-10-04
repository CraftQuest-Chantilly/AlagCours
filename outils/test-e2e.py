from playwright.sync_api import sync_playwright
import os
B=os.environ.get('ALCOURS_URL','http://127.0.0.1:8080/alcours/')
with sync_playwright() as p:
    b=p.chromium.launch()
    for nom,vp in [('desktop',{'width':1280,'height':900}),('mobile',{'width':390,'height':844})]:
        pg=b.new_page(viewport=vp); errs=[]
        pg.on('console', lambda m: errs.append(m.text) if m.type=='error' else None)
        pg.goto(B+'formations/123'); pg.wait_for_url('**/connexion'); print(nom,'garde route -> connexion OK')
        pg.fill('#login',os.environ['ALCOURS_LOGIN']); pg.fill('#mdp','mauvais'); pg.click('button[type=submit]')
        pg.wait_for_selector('[role=alert]'); print(nom,'erreur:',pg.inner_text('[role=alert]'))
        pg.fill('#mdp',os.environ['ALCOURS_LOGIN']); pg.screenshot(path=f'{nom}_1_connexion.png'); pg.click('button[type=submit]')
        pg.wait_for_selector('text=Mes formations'); pg.wait_for_selector('text=Agenda des séances')
        pg.wait_for_timeout(400); pg.screenshot(path=f'{nom}_2_formations.png', full_page=True)
        n=pg.locator('table tbody tr').count() if nom=='desktop' else pg.locator('ul.md\\:hidden li').count()
        print(nom,'formations affichées:',n)
        if nom=='desktop': pg.locator('table tbody tr').first.click()
        else: pg.locator('ul.md\\:hidden li button').first.click()
        pg.wait_for_selector('text=Modules'); pg.wait_for_timeout(300)
        print(nom,'modules:',[t.split('\n')[0] for t in pg.locator('[data-test=module]').all_inner_texts()])
        pg.screenshot(path=f'{nom}_3_formation.png', full_page=True)
        pg.reload(); pg.wait_for_selector('text=Modules'); print(nom,'rechargement conserve la session OK')
        pg.click('text=Déconnexion'); pg.wait_for_url('**/connexion'); print(nom,'déconnexion OK')
        print(nom,'erreurs console:',errs)
    b.close()
