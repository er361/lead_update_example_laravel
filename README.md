# Задача обновить цену лида 

> Общая логика такова. Через апи идет запрос на обновление цены лида руками админа.
> Система принимает эти данные и создает запись в таблице tasks для отправки на трекер
> который (делает свои задачи) и возвращает нам цену обновленного лида. Мы пишем его в базу 
> и переписываем транзакции по лиду с обновленной ценой.

### Теперь реализация подробная.
1. Точка входа LeadsUpdateController он принимает запрос через API
и вызывает UpdateLeadTrackerTaskCreateAction@execute чтобы тот создал запись в таблице 
tasks для отправки данных в трекер.
2. SendTaskLeadsJob он крутится периодически и проверяет таблицу tasks на
новые записи, назначая каждому таску свой обработчик.
3. SendTaskLeadsJob передает управление LeadUpdateTrackerJob который в
свою очередь отправляет данные на трекер.
4. После обработки данных трекер стучится сюда LeadsController@postback который 
 вызывает метод сервиса LeadsService@create.
5. LeadsService@create определяет тип postback-а, и передает управление конкретному экшену
в моем случае LeadActionsService@update.
6. LeadActionsService@update обновляет данные полученные через трекер в таблице leads 
и эмитит событие LeadUpdated.
7. На событие LeadUpdated подписаны слушатели LeadActionsLoggerSubscriber он просто пишет в лог
и LeadUpdateMoneyTransactionsListener.
8. LeadUpdateMoneyTransactionsListener он откатывает уже совершенные транзакции со старой ценой лида 
и накатывает новые с обновленной ценой.

